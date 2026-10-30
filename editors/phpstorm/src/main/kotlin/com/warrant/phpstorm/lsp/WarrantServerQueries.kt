package com.warrant.phpstorm.lsp

import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.components.service
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VfsUtil
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.LspServer
import com.intellij.platform.lsp.api.LspServerManager
import com.intellij.platform.lsp.api.LspServerState
import org.eclipse.lsp4j.DefinitionParams
import org.eclipse.lsp4j.Location
import org.eclipse.lsp4j.Position
import org.eclipse.lsp4j.TextDocumentIdentifier
import org.eclipse.lsp4j.jsonrpc.services.JsonRequest
import org.eclipse.lsp4j.services.LanguageServer
import java.net.URI
import java.nio.file.Path
import java.util.concurrent.CompletableFuture

/**
 * The Warrant language server's requests beyond the protocol's own, which the
 * descriptor makes the client able to send
 * ([WarrantLspServerDescriptor.lsp4jServerClass]).
 */
interface WarrantLanguageServer : LanguageServer {

    /** The condition, template or ability named at a position, and what declares it. */
    @JsonRequest("warrant/declaration")
    fun declaration(params: WarrantPositionParams): CompletableFuture<WarrantDeclaration?>

    /** Where what a member of a file declares is used in the project's rule text. */
    @JsonRequest("warrant/usages")
    fun usages(params: WarrantUsagesParams): CompletableFuture<WarrantUsages?>
}

/** A position in a document the server has open. */
data class WarrantPositionParams(val textDocument: TextDocumentIdentifier, val position: Position)

/**
 * A declaration, named as the PHP knows it: by its file and the method or
 * constant in it that declares it.
 */
data class WarrantUsagesParams(val uri: String, val member: String)

/**
 * The condition, template or ability a name in rule text names.
 *
 * @property kind `condition`, `template` or `ability`.
 * @property name The name rule text uses.
 * @property uri The file it is declared in.
 * @property member The method or constant that declares it.
 */
data class WarrantDeclaration(val kind: String?, val name: String?, val uri: String?, val member: String?)

/**
 * The uses of a declaration in the project's rule text.
 *
 * @property kind `condition`, `template` or `ability`.
 * @property name The name rule text uses.
 * @property usages Where each use is written.
 * @property taken The names its kind already has in each schema that has it,
 *   which a rename must not take.
 */
data class WarrantUsages(val kind: String?, val name: String?, val usages: List<Location>?, val taken: List<String>?)

/**
 * Asks the project's running Warrant language server about the conditions,
 * templates and abilities rule text names. Every answer is null when no server
 * is running or it does not answer in time.
 *
 * It is registered in `warrant-lsp.xml` rather than as a light service, which
 * must be final, so tests can stand a fake server in for it.
 */
open class WarrantServerQueries(private val project: Project) {

    /** Whether the user has been told rule text went unsearched. */
    private var notified = false

    /**
     * What the name at [hostOffset] of [hostFile] names, where [hostFile] is the
     * `.warrant` file or the PHP file holding the rule text.
     */
    open fun declarationAt(hostFile: VirtualFile, hostOffset: Int): WarrantDeclaration? {
        val server = runningServer() ?: return null
        val params = WarrantPositionParams(server.getDocumentIdentifier(hostFile), positionOf(hostFile, hostOffset) ?: return null)

        return ask(server) { it.declaration(params) }
    }

    /**
     * Where the declaration named by its ([file], [member]) is used. When no
     * server is running, says so once in a notification, since rule text then
     * goes unsearched, unless [notifyIfUnsearched] is false.
     */
    open fun usagesOf(file: VirtualFile, member: String, notifyIfUnsearched: Boolean = true): WarrantUsages? {
        val server = runningServer()

        if (server == null) {
            if (notifyIfUnsearched) notifyUnsearched()
            return null
        }

        val params = WarrantUsagesParams(server.getDocumentIdentifier(file).uri, member)

        // The first search of a project reads all of it, so it is given longer than a lookup.
        return ask(server, SEARCH_TIMEOUT_MS) { it.usages(params) }
    }

    /**
     * Where the server says the name at [hostOffset] of [hostFile] is declared,
     * for a name that is no condition, template or ability: a schema, or the
     * `as` that names a frame.
     */
    open fun definitionAt(hostFile: VirtualFile, hostOffset: Int): List<Location> {
        val server = runningServer() ?: return emptyList()
        val params = DefinitionParams(server.getDocumentIdentifier(hostFile), positionOf(hostFile, hostOffset) ?: return emptyList())

        val answer = try {
            server.sendRequestSync(REQUEST_TIMEOUT_MS) { it.textDocumentService.definition(params) }
        } catch (_: Exception) {
            null
        } ?: return emptyList()

        return if (answer.isLeft) {
            answer.left.orEmpty()
        } else {
            answer.right.orEmpty().map { Location(it.targetUri, it.targetSelectionRange) }
        }
    }

    /** The file a URI the server gave names. */
    open fun fileOf(uri: String): VirtualFile? =
        runningServer()?.descriptor?.findFileByUri(uri)
            ?: runCatching { VfsUtil.findFile(Path.of(URI(uri)), true) }.getOrNull()

    private fun runningServer(): LspServer? =
        LspServerManager.getInstance(project)
            .getServersForProvider(WarrantLspServerSupportProvider::class.java)
            .firstOrNull { it.state == LspServerState.Running }

    private fun <T> ask(
        server: LspServer,
        timeout: Int = REQUEST_TIMEOUT_MS,
        request: (WarrantLanguageServer) -> CompletableFuture<T?>,
    ): T? =
        try {
            server.sendRequestSync(timeout) { request(it as WarrantLanguageServer) }
        } catch (_: Exception) {
            null
        }

    private fun positionOf(file: VirtualFile, offset: Int): Position? {
        val document = FileDocumentManager.getInstance().getDocument(file) ?: return null
        val line = document.getLineNumber(offset)

        // Document offsets count UTF-16 units, as LSP positions do.
        return Position(line, offset - document.getLineStartOffset(line))
    }

    private fun notifyUnsearched() {
        if (notified) return
        notified = true

        NotificationGroupManager.getInstance().getNotificationGroup("Warrant")
            .createNotification(
                "Warrant rule text was not searched",
                "The Warrant language server is not running, so uses of conditions, templates and abilities in rule text are not found or renamed.",
                NotificationType.WARNING,
            )
            .notify(project)
    }

    companion object {
        /** How long a request waits for the server, in milliseconds. */
        private const val REQUEST_TIMEOUT_MS = 2000

        /** How long a search of the project's rule text waits for the server, in milliseconds. */
        private const val SEARCH_TIMEOUT_MS = 10000

        fun getInstance(project: Project): WarrantServerQueries = project.service()
    }
}
