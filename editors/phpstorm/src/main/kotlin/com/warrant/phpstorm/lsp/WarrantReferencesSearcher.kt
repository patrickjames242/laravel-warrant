package com.warrant.phpstorm.lsp

import com.intellij.openapi.application.QueryExecutorBase
import com.intellij.openapi.application.runReadAction
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.util.TextRange
import com.intellij.psi.PsiManager
import com.intellij.psi.PsiReference
import com.intellij.psi.search.PsiSearchScopeUtil
import com.intellij.psi.search.searches.ReferencesSearch
import com.intellij.psi.util.PsiTreeUtil
import com.intellij.util.Processor
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression
import org.eclipse.lsp4j.Location

/**
 * Finds the uses in rule text of a condition, template or ability, for Find
 * Usages, Show Usages and Rename on the PHP that declares it: the method, the
 * key given to its attribute, or the ability constant's value.
 *
 * The language server says where the uses are, having looked each name up as
 * validation does, and each becomes a [WarrantNameReference] on the name written
 * there, which a rename rewrites.
 */
class WarrantReferencesSearcher : QueryExecutorBase<PsiReference, ReferencesSearch.SearchParameters>(false) {

    override fun processQuery(parameters: ReferencesSearch.SearchParameters, consumer: Processor<in PsiReference>) {
        val construct = runReadAction { WarrantConstructs.declaredBy(parameters.elementToSearch) } ?: return
        val project = parameters.project
        val usages = WarrantServerQueries.getInstance(project).usagesOf(construct.file, construct.member) ?: return

        for (usage in usages.usages.orEmpty()) {
            val reference = runReadAction { referenceAt(parameters, construct, usage) } ?: continue

            if (!consumer.process(reference)) return
        }
    }

    /**
     * The reference to [construct] at [usage], or null when the place is gone
     * or outside the search's scope.
     *
     * A name the editor injects Warrant around is a [com.warrant.phpstorm.WarrantName];
     * one in a PHP string the server reads as rule text but the editor does not
     * inject into is a range of that string.
     */
    private fun referenceAt(
        parameters: ReferencesSearch.SearchParameters,
        construct: WarrantConstruct,
        usage: Location,
    ): PsiReference? {
        val project = parameters.project
        val file = WarrantServerQueries.getInstance(project).fileOf(usage.uri) ?: return null
        val psiFile = PsiManager.getInstance(project).findFile(file) ?: return null
        val document = FileDocumentManager.getInstance().getDocument(file) ?: return null
        val start = offsetOf(document, usage.range.start.line, usage.range.start.character) ?: return null
        val end = offsetOf(document, usage.range.end.line, usage.range.end.character) ?: return null

        val name = nameAt(psiFile, start)

        if (name != null) {
            if (!PsiSearchScopeUtil.isInScope(parameters.effectiveSearchScope, name)) return null

            return WarrantNameReference(name, TextRange(0, name.textLength), construct)
        }

        val literal = PsiTreeUtil.getParentOfType(psiFile.findElementAt(start), StringLiteralExpression::class.java, false)
            ?: return null

        if (!PsiSearchScopeUtil.isInScope(parameters.effectiveSearchScope, literal)) return null

        val literalStart = literal.textRange.startOffset

        if (end > literal.textRange.endOffset) return null

        return WarrantNameReference(literal, TextRange(start - literalStart, end - literalStart), construct)
    }

    private fun offsetOf(document: com.intellij.openapi.editor.Document, line: Int, character: Int): Int? {
        if (line < 0 || line >= document.lineCount) return null

        val offset = document.getLineStartOffset(line) + character

        return if (offset > document.getLineEndOffset(line)) null else offset
    }
}
