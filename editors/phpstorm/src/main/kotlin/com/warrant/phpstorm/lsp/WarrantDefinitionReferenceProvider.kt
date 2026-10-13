package com.warrant.phpstorm.lsp

import com.intellij.lang.injection.InjectedLanguageManager
import com.intellij.model.Pointer
import com.intellij.model.Symbol
import com.intellij.model.psi.ImplicitReferenceProvider
import com.intellij.model.psi.PsiSymbolReference
import com.intellij.navigation.NavigatableSymbol
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.util.TextRange
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.backend.navigation.NavigationRequest
import com.intellij.platform.backend.navigation.NavigationTarget
import com.intellij.platform.backend.presentation.TargetPresentation
import com.intellij.platform.lsp.api.LspServer
import com.intellij.platform.lsp.api.LspServerManager
import com.intellij.platform.lsp.api.LspServerState
import com.intellij.psi.PsiElement
import com.warrant.phpstorm.WarrantTokenTypes
import org.eclipse.lsp4j.DefinitionParams
import org.eclipse.lsp4j.Location
import org.eclipse.lsp4j.LocationLink
import org.eclipse.lsp4j.Position
import org.eclipse.lsp4j.Range

/**
 * Makes each name in Warrant text a reference to where the language server says
 * it is declared, so the editor underlines it on Cmd/Ctrl-hover and navigates on
 * Cmd/Ctrl-click.
 *
 * The platform's own LSP navigation asks the server only while the Go to
 * Declaration action runs, which a hover is not, so it never underlines. This
 * provider asks on hover too, and the descriptor turns the platform's navigation
 * off ([WarrantLspServerDescriptor.lspGoToDefinitionSupport]) so a click is
 * answered once.
 *
 * A name inside a PHP string is asked about at its place in the PHP file, which
 * is the document the server has open; the server finds the Warrant in it.
 */
class WarrantDefinitionReferenceProvider : ImplicitReferenceProvider {

    override fun getImplicitReference(element: PsiElement, offsetInElement: Int): PsiSymbolReference? {
        if (element.node?.elementType != WarrantTokenTypes.IDENTIFIER) return null

        val project = element.project
        val injection = InjectedLanguageManager.getInstance(project)
        val hostFile = injection.getTopLevelFile(element)?.virtualFile ?: return null

        if (!WarrantLspServerDescriptor.isWarrantOrPhpFile(hostFile)) return null

        val server = LspServerManager.getInstance(project)
            .getServersForProvider(WarrantLspServerSupportProvider::class.java)
            .firstOrNull { it.state == LspServerState.Running }
            ?: return null

        val hostOffset = injection.injectedToHost(element, element.textRange.startOffset)

        return WarrantDefinitionReference(element, server, hostFile, hostOffset)
    }
}

/**
 * A name in Warrant text, resolved by asking the language server for its
 * definition.
 *
 * @param element The name's token.
 * @param server The running Warrant language server.
 * @param hostFile The file the server has open: the `.warrant` file, or the PHP
 *   file the name is written in.
 * @param hostOffset Where the name starts in [hostFile].
 */
private class WarrantDefinitionReference(
    private val element: PsiElement,
    private val server: LspServer,
    private val hostFile: VirtualFile,
    private val hostOffset: Int,
) : PsiSymbolReference {

    override fun getElement(): PsiElement = element

    override fun getRangeInElement(): TextRange = TextRange(0, element.textLength)

    override fun resolveReference(): Collection<Symbol> {
        val document = FileDocumentManager.getInstance().getDocument(hostFile) ?: return emptyList()
        val line = document.getLineNumber(hostOffset)
        // Document offsets count UTF-16 units, as LSP positions do.
        val position = Position(line, hostOffset - document.getLineStartOffset(line))
        val params = DefinitionParams(server.getDocumentIdentifier(hostFile), position)

        val answer = try {
            server.sendRequestSync(REQUEST_TIMEOUT_MS) { it.textDocumentService.definition(params) }
        } catch (_: Exception) {
            null
        } ?: return emptyList()

        val targets: List<Pair<String, Range>> = if (answer.isLeft) {
            answer.left.orEmpty().map { location: Location -> location.uri to location.range }
        } else {
            answer.right.orEmpty().map { link: LocationLink -> link.targetUri to link.targetSelectionRange }
        }

        return targets.mapNotNull { (uri, range) ->
            server.descriptor.findFileByUri(uri)?.let { file -> WarrantDefinitionSymbol(element.project, file, range) }
        }
    }

    private companion object {
        /** How long a hover waits for the server, in milliseconds. */
        const val REQUEST_TIMEOUT_MS = 1000
    }
}

/**
 * Where the language server says a name is declared.
 *
 * @param project The project the name is in.
 * @param file The file it is declared in.
 * @param range Where in that file, as the server gave it.
 */
private data class WarrantDefinitionSymbol(
    private val project: Project,
    private val file: VirtualFile,
    private val range: Range,
) : NavigatableSymbol {

    override fun createPointer(): Pointer<out Symbol> = Pointer.hardPointer(this)

    override fun getNavigationTargets(project: Project): Collection<NavigationTarget> =
        listOf(WarrantDefinitionTarget(project, file, range))
}

/**
 * The place in a file a definition is navigated to.
 *
 * @param project The project the navigation happens in.
 * @param file The file the definition is in.
 * @param range Where in that file, as the server gave it.
 */
private data class WarrantDefinitionTarget(
    private val project: Project,
    private val file: VirtualFile,
    private val range: Range,
) : NavigationTarget {

    override fun createPointer(): Pointer<out NavigationTarget> = Pointer.hardPointer(this)

    override fun computePresentation(): TargetPresentation =
        TargetPresentation.builder(file.name)
            .locationText("${file.name}:${range.start.line + 1}")
            .presentation()

    override fun navigationRequest(): NavigationRequest? {
        val document = FileDocumentManager.getInstance().getDocument(file) ?: return null
        val line = range.start.line.coerceIn(0, maxOf(0, document.lineCount - 1))
        val offset = (document.getLineStartOffset(line) + range.start.character)
            .coerceAtMost(document.getLineEndOffset(line))

        return NavigationRequest.sourceNavigationRequest(project, file, offset)
    }
}
