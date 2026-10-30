package com.warrant.phpstorm.lsp

import com.intellij.codeInspection.reference.EntryPoint
import com.intellij.codeInspection.reference.RefElement
import com.intellij.psi.PsiElement
import com.jetbrains.php.lang.psi.elements.Field
import com.jetbrains.php.lang.psi.elements.Method
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression
import org.jdom.Element

/**
 * Keeps PhpStorm from showing a condition or template method, or an ability
 * constant, as unused while rule text names it. Warrant calls these by
 * reflection, so no PHP calls them; rule text naming one is what uses it.
 *
 * Whether rule text names one is the language server's to say, and is read
 * from [WarrantNamesInRuleText], which never waits on it. When it cannot say,
 * because it is not running or has not answered yet, does not know the schema,
 * or the ability's name is computed, the member is taken to be used, so nothing
 * is shown unused that may not be.
 */
class WarrantEntryPoint : EntryPoint() {

    /** Whether the entry point is on, which the unused-declaration inspection's settings toggle. */
    private var selected: Boolean = true

    override fun getDisplayName(): String = "Warrant conditions, templates and abilities named in rule text"

    override fun isEntryPoint(refElement: RefElement, psiElement: PsiElement): Boolean = isEntryPoint(psiElement)

    override fun isEntryPoint(psiElement: PsiElement): Boolean {
        val namedBy = when (psiElement) {
            is Method -> {
                if (WarrantConstructs.kindOf(psiElement) == null) return false
                WarrantConstructs.keyOf(psiElement) ?: psiElement
            }

            is Field -> {
                if (!WarrantConstructs.isAbilityConstant(psiElement)) return false
                psiElement.defaultValue as? StringLiteralExpression ?: return true
            }

            else -> return false
        }

        val construct = WarrantConstructs.declaredBy(namedBy) ?: return true

        return WarrantNamesInRuleText.getInstance(namedBy.project).isNamed(construct)
    }

    override fun isSelected(): Boolean = selected

    override fun setSelected(selected: Boolean) {
        this.selected = selected
    }

    override fun readExternal(element: Element) {
        selected = element.getAttributeValue(SELECTED)?.toBooleanStrictOrNull() ?: true
    }

    override fun writeExternal(element: Element) {
        if (!selected) element.setAttribute(SELECTED, "false")
    }

    private companion object {
        /** The attribute the setting is saved under, written only when it is off. */
        const val SELECTED = "selected"
    }
}
