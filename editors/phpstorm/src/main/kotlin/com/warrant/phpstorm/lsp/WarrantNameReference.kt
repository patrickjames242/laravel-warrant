package com.warrant.phpstorm.lsp

import com.intellij.lang.injection.InjectedLanguageManager
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.util.TextRange
import com.intellij.patterns.PlatformPatterns
import com.intellij.psi.ElementManipulators
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiFile
import com.intellij.psi.PsiManager
import com.intellij.psi.PsiReference
import com.intellij.psi.PsiReferenceBase
import com.intellij.psi.PsiReferenceContributor
import com.intellij.psi.PsiReferenceProvider
import com.intellij.psi.PsiReferenceRegistrar
import com.intellij.psi.impl.source.resolve.ResolveCache
import com.intellij.psi.util.PsiTreeUtil
import com.intellij.util.ProcessingContext
import com.jetbrains.php.lang.psi.elements.PhpClass
import com.warrant.phpstorm.WarrantName
import org.eclipse.lsp4j.Location

/**
 * Makes every name in Warrant text a [WarrantNameReference], so Cmd/Ctrl-hover
 * underlines it, Cmd/Ctrl-click goes to what declares it, and a rename started
 * on it renames that.
 */
class WarrantNameReferenceContributor : PsiReferenceContributor() {

    override fun registerReferenceProviders(registrar: PsiReferenceRegistrar) {
        registrar.registerReferenceProvider(
            PlatformPatterns.psiElement(WarrantName::class.java),
            object : PsiReferenceProvider() {
                override fun getReferencesByElement(element: PsiElement, context: ProcessingContext): Array<PsiReference> =
                    arrayOf(WarrantNameReference(element, TextRange(0, element.textLength)))
            },
        )
    }
}

/**
 * A name written in Warrant text, resolved by asking the language server what it
 * names: a condition, template or ability resolves to the PHP its name is taken
 * from ([WarrantConstructs.declaringElement]), and a schema or frame to where the
 * server says it is declared.
 *
 * A name inside a PHP string is asked about at its place in the PHP file, which
 * is the document the server has open; the server finds the Warrant in it.
 *
 * Its uses are found only by [WarrantReferencesSearcher], which asks the server
 * for them, so [isReferenceTo] is never true: a name is never looked up by
 * matching text, which would ask the server about every name with the same
 * spelling.
 *
 * @param element The name: a [WarrantName], or a PHP string the name is written
 *   in when the string is rule text the server reads but the editor does not
 *   inject Warrant into.
 * @param range Where the name is in [element].
 * @param construct What the name is known to name, when it was found by
 *   searching for that.
 */
class WarrantNameReference(
    element: PsiElement,
    range: TextRange,
    private val construct: WarrantConstruct? = null,
) : PsiReferenceBase<PsiElement>(element, range, true) {

    override fun resolve(): PsiElement? =
        construct?.nameDeclaredBy
            ?: ResolveCache.getInstance(element.project).resolveWithCaching(this, RESOLVER, false, false)

    override fun isReferenceTo(element: PsiElement): Boolean = false

    /**
     * Writes the name rule text uses once what declares it is renamed to
     * [newElementName]: a method's new name in snake case, or a key's or
     * ability's new string as it is.
     */
    override fun handleElementRename(newElementName: String): PsiElement {
        val renamed = construct ?: resolve()?.let(WarrantConstructs::declaredBy) ?: return element

        return ElementManipulators.handleContentChange(element, rangeInElement, renamed.nameAfterRename(newElementName))
    }

    private companion object {
        val RESOLVER = ResolveCache.AbstractResolver<WarrantNameReference, PsiElement?> { reference, _ -> reference.ask() }
    }

    private fun ask(): PsiElement? {
        val project = element.project
        val injection = InjectedLanguageManager.getInstance(project)
        val hostFile = injection.getTopLevelFile(element)?.virtualFile ?: return null

        val hostOffset = injection.injectedToHost(element, element.textRange.startOffset + rangeInElement.startOffset)
        val queries = WarrantServerQueries.getInstance(project)

        queries.declarationAt(hostFile, hostOffset)?.let { declaration ->
            return WarrantConstructs.declaringElement(project, declaration)
        }

        return queries.definitionAt(hostFile, hostOffset).firstNotNullOfOrNull { elementAt(it) }
    }

    /**
     * What is declared at [location]: the name in Warrant text written there,
     * or the PHP class declared on that line.
     */
    private fun elementAt(location: Location): PsiElement? {
        val project = element.project
        val file = WarrantServerQueries.getInstance(project).fileOf(location.uri) ?: return null
        val psiFile = PsiManager.getInstance(project).findFile(file) ?: return null
        val document = FileDocumentManager.getInstance().getDocument(file) ?: return null

        if (document.lineCount == 0) return psiFile

        val line = location.range.start.line.coerceIn(0, document.lineCount - 1)
        val lineEnd = document.getLineEndOffset(line)
        var offset = (document.getLineStartOffset(line) + location.range.start.character).coerceAtMost(lineEnd)

        // A declaration's line is given from its start; the class is what follows the indent.
        while (offset < lineEnd && document.charsSequence[offset].isWhitespace()) offset++

        return nameAt(psiFile, offset)
            ?: psiFile.findElementAt(offset)?.let { PsiTreeUtil.getParentOfType(it, PhpClass::class.java, false) ?: it }
    }
}

/**
 * The [WarrantName] at [offset] of [file], in the file itself or in Warrant
 * injected into it, or null when there is none.
 */
fun nameAt(file: PsiFile, offset: Int): WarrantName? {
    val element = InjectedLanguageManager.getInstance(file.project).findInjectedElementAt(file, offset)
        ?: file.findElementAt(offset)

    return PsiTreeUtil.getParentOfType(element, WarrantName::class.java, false)
}
