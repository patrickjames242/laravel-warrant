package com.warrant.phpstorm.lsp

import com.intellij.formatting.FormattingContext
import com.intellij.formatting.service.AbstractDocumentFormattingService
import com.intellij.formatting.service.FormattingService
import com.intellij.lang.injection.InjectedLanguageManager
import com.intellij.openapi.editor.Document
import com.intellij.openapi.util.TextRange
import com.intellij.psi.PsiFile
import com.warrant.phpstorm.WarrantFile

/**
 * Formats a `.warrant` file on Reformat Code, and so on Actions on Save, by
 * running `warrant-format` through [WarrantRuleTextFormatter].
 *
 * It formats while the reformat runs, before the file is saved, so a save that
 * formats leaves the file saved. The whole file is formatted whatever range was
 * asked for, a selection or the lines a save changed, since the formatter lays
 * out whole rules. Formatting that may only change whitespace, or that runs as
 * text is typed, is left alone, because the formatter also writes quotes and
 * parentheses its own way.
 */
class WarrantFormattingService : AbstractDocumentFormattingService() {

    override fun getFeatures(): Set<FormattingService.Feature> = setOf(FormattingService.Feature.FORMAT_FRAGMENTS)

    override fun canFormat(file: PsiFile): Boolean =
        file is WarrantFile &&
            !InjectedLanguageManager.getInstance(file.project).isInjectedFragment(file) &&
            file.virtualFile?.isInLocalFileSystem == true

    override fun formatDocument(
        document: Document,
        formattingRanges: List<TextRange>,
        formattingContext: FormattingContext,
        canChangeWhiteSpaceOnly: Boolean,
        quickFormat: Boolean,
    ) {
        if (canChangeWhiteSpaceOnly || quickFormat) return

        val file = formattingContext.containingFile.virtualFile ?: return
        val formatted = WarrantRuleTextFormatter.formatted(formattingContext.project, file.path, document.text) ?: return

        WarrantRuleTextFormatter.applyTo(document, formatted)
    }
}
