package com.warrant.phpstorm

import com.intellij.extapi.psi.ASTWrapperPsiElement
import com.intellij.lang.ASTNode
import com.intellij.openapi.util.TextRange
import com.intellij.psi.AbstractElementManipulator
import com.intellij.psi.ContributedReferenceHost
import com.intellij.psi.PsiFileFactory
import com.intellij.psi.PsiReference
import com.intellij.psi.impl.source.resolve.reference.ReferenceProvidersRegistry
import com.intellij.psi.tree.IElementType
import com.intellij.psi.util.PsiTreeUtil

/** The element type of a name written in Warrant text. */
val WARRANT_NAME: IElementType = WarrantTokenType("WARRANT_NAME")

/**
 * A name written in Warrant text: one [WarrantTokenTypes.IDENTIFIER] token.
 *
 * Its references are contributed, by the language-server client when there is
 * one, so a name can be followed to the PHP that declares it and renamed with
 * it.
 */
class WarrantName(node: ASTNode) : ASTWrapperPsiElement(node), ContributedReferenceHost {

    override fun getReferences(): Array<PsiReference> = ReferenceProvidersRegistry.getReferencesFromProviders(this)

    override fun getReference(): PsiReference? = references.firstOrNull()
}

/**
 * Changes the text of a [WarrantName], which a rename does to every use of what
 * it names. The name is replaced by one parsed from the new text, in place, so
 * a name injected into a PHP string changes the string.
 */
class WarrantNameManipulator : AbstractElementManipulator<WarrantName>() {

    override fun handleContentChange(element: WarrantName, range: TextRange, newContent: String): WarrantName {
        val text = range.replace(element.text, newContent)
        val file = PsiFileFactory.getInstance(element.project).createFileFromText("name.warrant", WarrantLanguage, text)
        val replacement = PsiTreeUtil.findChildOfType(file, WarrantName::class.java) ?: return element

        return element.replace(replacement) as WarrantName
    }
}
