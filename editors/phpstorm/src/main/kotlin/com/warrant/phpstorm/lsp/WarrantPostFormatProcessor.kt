package com.warrant.phpstorm.lsp

import com.intellij.openapi.util.TextRange
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiFile
import com.intellij.psi.codeStyle.CodeStyleSettings
import com.intellij.psi.impl.source.codeStyle.PostFormatProcessor
import com.jetbrains.php.lang.PhpFileType

/**
 * Formats the Warrant in a PHP file as part of PhpStorm's own Reformat Code,
 * after the PHP has been formatted, so Reformat Code and Actions on Save format
 * the rule text in a PHP file as they do the PHP around it.
 *
 * A `.warrant` file is formatted by the language server instead, through
 * [WarrantLspServerDescriptor.lspFormattingSupport].
 *
 * The rule text is formatted only when every change falls inside the range
 * being reformatted, so reformatting a selection never reaches past it.
 */
class WarrantPostFormatProcessor : PostFormatProcessor {

    override fun processElement(source: PsiElement, settings: CodeStyleSettings): PsiElement = source

    override fun processText(source: PsiFile, rangeToReformat: TextRange, settings: CodeStyleSettings): TextRange {
        val file = source.virtualFile ?: return rangeToReformat
        if (source.fileType != PhpFileType.INSTANCE || !file.isInLocalFileSystem) return rangeToReformat

        val documents = PsiDocumentManager.getInstance(source.project)
        val document = documents.getDocument(source) ?: return rangeToReformat
        documents.doPostponedOperationsAndUnblockDocument(document)

        val text = document.text
        if (!WarrantRuleTextFormatter.mightHoldRuleText(text)) return rangeToReformat

        val formatted = WarrantRuleTextFormatter.formatted(source.project, file.path, text) ?: return rangeToReformat
        val start = text.commonPrefixWith(formatted).length
        val end = text.length - text.substring(start).commonSuffixWith(formatted.substring(start)).length

        if (start < rangeToReformat.startOffset || end > rangeToReformat.endOffset) return rangeToReformat

        WarrantRuleTextFormatter.applyTo(document, formatted)
        documents.commitDocument(document)

        return rangeToReformat.grown(formatted.length - text.length)
    }
}
