package com.warrant.phpstorm.lsp

import com.intellij.codeInsight.navigation.actions.GotoDeclarationHandler
import com.intellij.find.findUsages.FindUsagesHandler
import com.intellij.find.findUsages.FindUsagesHandlerFactory
import com.intellij.find.findUsages.PsiElement2UsageTargetAdapter
import com.intellij.openapi.editor.Editor
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiFile
import com.intellij.psi.search.GlobalSearchScope
import com.intellij.psi.search.searches.ReferencesSearch
import com.intellij.usages.UsageTarget
import com.intellij.usages.UsageTargetProvider
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression

/**
 * Finds the usages of a condition, template or ability from the PHP string that
 * holds its name, which the platform does not search from, since the string is
 * no named element. The usages are [WarrantReferencesSearcher]'s.
 */
class WarrantNameStringFindUsagesHandlerFactory : FindUsagesHandlerFactory() {

    override fun canFindUsages(element: PsiElement): Boolean =
        element is StringLiteralExpression && WarrantConstructs.declaredBy(element) != null

    override fun createFindUsagesHandler(element: PsiElement, forHighlightUsages: Boolean): FindUsagesHandler =
        object : FindUsagesHandler(element) {}
}

/** Makes the string at the caret what Find Usages searches for, when it holds a Warrant name. */
class WarrantNameStringUsageTargetProvider : UsageTargetProvider {

    override fun getTargets(editor: Editor, file: PsiFile): Array<UsageTarget>? {
        val string = nameStringAt(file, editor.caretModel.offset) ?: return null

        return arrayOf(PsiElement2UsageTargetAdapter(string, true))
    }
}

/**
 * Cmd/Ctrl-click on a PHP string holding a condition's, template's or ability's
 * name offers the places rule text uses it, as Cmd/Ctrl-click on a method's name
 * offers its usages.
 */
class WarrantNameStringGotoUsagesHandler : GotoDeclarationHandler {

    override fun getGotoDeclarationTargets(sourceElement: PsiElement?, offset: Int, editor: Editor?): Array<PsiElement>? {
        val file = sourceElement?.containingFile ?: return null
        val string = nameStringAt(file, offset) ?: return null

        return ReferencesSearch.search(string, GlobalSearchScope.projectScope(file.project))
            .findAll()
            .map { it.element }
            .toTypedArray()
            .takeIf { it.isNotEmpty() }
    }
}
