package com.warrant.phpstorm.lsp

import com.intellij.codeInsight.completion.CompletionParameters
import com.intellij.execution.ExecutionException
import com.intellij.execution.configurations.GeneralCommandLine
import com.intellij.openapi.project.Project
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.ProjectWideLspServerDescriptor
import com.intellij.platform.lsp.api.customization.LspCompletionSupport
import com.intellij.platform.lsp.api.customization.LspFormattingSupport
import com.intellij.psi.util.PsiTreeUtil
import com.jetbrains.php.lang.PhpFileType
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression
import com.warrant.phpstorm.WarrantFileType
import com.warrant.phpstorm.WarrantLanguage
import java.nio.charset.StandardCharsets

/**
 * Describes the Warrant language server: how to launch it and which files it
 * serves.
 *
 * The server takes whole PHP files, finds the Warrant inside them itself and
 * answers in the PHP file's own coordinates, so PHP files are sent to it as
 * they are and no position is mapped on this side. Diagnostics, hover,
 * completion and quick fixes use the platform's defaults, go-to-definition
 * is answered by [WarrantDefinitionReferenceProvider], Reformat Code formats a
 * `.warrant` file through the server, and [ReformatWarrantRulesAction] formats
 * the Warrant in a PHP file;
 * the server answers nothing for a position outside Warrant text, which leaves
 * PhpStorm's own PHP features untouched.
 */
class WarrantLspServerDescriptor(project: Project) : ProjectWideLspServerDescriptor(project, "Warrant") {

    override fun isSupportedFile(file: VirtualFile): Boolean = isWarrantOrPhpFile(file)

    override fun createCommandLine(): GeneralCommandLine {
        val launch = WarrantServerLaunch.resolve(project)
            ?: throw ExecutionException("The Warrant language server is disabled, or no warrant-language-server script was found")

        return GeneralCommandLine(launch.phpExecutable, launch.script.toString())
            .withWorkDirectory(launch.workDirectory.toFile())
            .withParentEnvironmentType(GeneralCommandLine.ParentEnvironmentType.CONSOLE)
            .withCharset(StandardCharsets.UTF_8)
    }

    override fun createInitializationOptions(): Any =
        mapOf("vocabulary" to WarrantSettings.getInstance(project).state.vocabulary.wireValue)

    override fun getLanguageId(file: VirtualFile): String =
        if (file.fileType == WarrantFileType.INSTANCE) "warrant" else "php"

    /**
     * Off, because [WarrantDefinitionReferenceProvider] answers go-to-definition
     * itself: it asks the server on Cmd/Ctrl-hover as well as on click, so names
     * are underlined, and a second answer from the platform would offer each
     * target twice.
     */
    override val lspGoToDefinitionSupport: Boolean = false

    /**
     * Asks the server for completions only in a `.warrant` file, in injected
     * Warrant, or inside a PHP string, the only places Warrant text can stand,
     * so completion elsewhere in PHP sends no request.
     */
    override val lspCompletionSupport: LspCompletionSupport = object : LspCompletionSupport() {
        override fun shouldRunCodeCompletion(parameters: CompletionParameters): Boolean {
            val position = parameters.position
            val inWarrantText = position.language == WarrantLanguage ||
                PsiTreeUtil.getParentOfType(position, StringLiteralExpression::class.java, false) != null

            return inWarrantText && super.shouldRunCodeCompletion(parameters)
        }
    }

    /**
     * Reformat Code formats a `.warrant` file through the server, which is the
     * one formatter of Warrant text. A PHP file stays PhpStorm's to format, and
     * [WarrantPostFormatProcessor] formats the Warrant in it after.
     */
    override val lspFormattingSupport: LspFormattingSupport = object : LspFormattingSupport() {
        override fun shouldFormatThisFileExclusivelyByServer(
            file: VirtualFile,
            ideCanFormatThisFileItself: Boolean,
            serverExplicitlyWantsToFormatThisFile: Boolean,
        ): Boolean = file.fileType == WarrantFileType.INSTANCE
    }

    companion object {
        /** Whether [file] is a local `.warrant` or PHP file. */
        fun isWarrantOrPhpFile(file: VirtualFile): Boolean =
            file.isInLocalFileSystem &&
                (file.fileType == WarrantFileType.INSTANCE || file.fileType == PhpFileType.INSTANCE)
    }
}
