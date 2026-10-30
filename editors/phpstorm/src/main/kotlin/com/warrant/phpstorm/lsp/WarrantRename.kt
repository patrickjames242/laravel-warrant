package com.warrant.phpstorm.lsp

import com.intellij.openapi.actionSystem.CommonDataKeys
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.actionSystem.DataContext
import com.intellij.openapi.editor.Editor
import com.intellij.openapi.project.Project
import com.intellij.patterns.ElementPattern
import com.intellij.patterns.PatternCondition
import com.intellij.patterns.PlatformPatterns
import com.intellij.psi.ElementDescriptionLocation
import com.intellij.psi.ElementDescriptionProvider
import com.intellij.psi.ElementManipulators
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiFile
import com.intellij.psi.util.PsiTreeUtil
import com.intellij.refactoring.listeners.RefactoringElementListener
import com.intellij.refactoring.rename.PsiElementRenameHandler
import com.intellij.refactoring.rename.RenameHandler
import com.intellij.refactoring.rename.RenameInputValidatorEx
import com.intellij.refactoring.rename.RenamePsiElementProcessor
import com.intellij.usageView.UsageInfo
import com.intellij.usageView.UsageViewLongNameLocation
import com.intellij.usageView.UsageViewShortNameLocation
import com.intellij.usageView.UsageViewTypeLocation
import com.intellij.util.ProcessingContext
import com.jetbrains.php.lang.psi.elements.Method
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression

/**
 * Renames a condition or template whose name is a key given to its attribute,
 * or an ability, whose name is its constant's value: the string, and every use
 * of the name in rule text.
 *
 * A PHP string is no named element, so the platform renames one only through a
 * processor that takes it; the uses come from [WarrantReferencesSearcher].
 */
class WarrantNameStringRenameProcessor : RenamePsiElementProcessor() {

    override fun canProcessElement(element: PsiElement): Boolean =
        element is StringLiteralExpression && WarrantConstructs.declaredBy(element) != null

    override fun isInplaceRenameSupported(): Boolean = false

    override fun renameElement(
        element: PsiElement,
        newName: String,
        usages: Array<out UsageInfo>,
        listener: RefactoringElementListener?,
    ) {
        for (usage in usages) {
            usage.reference?.handleElementRename(newName)
        }

        val renamed = ElementManipulators.handleContentChange(element, newName)
        listener?.elementRenamed(renamed ?: element)
    }
}

/**
 * Starts a rename on a PHP string that is a condition's or template's key or an
 * ability's name, which the platform's own rename does not take, since the
 * string is no named element.
 */
class WarrantNameStringRenameHandler : RenameHandler {

    override fun isAvailableOnDataContext(dataContext: DataContext): Boolean = nameStringAtCaret(dataContext) != null

    override fun invoke(project: Project, editor: Editor?, file: PsiFile?, dataContext: DataContext) {
        val string = nameStringAtCaret(dataContext) ?: return

        rename(string, project, editor, dataContext)
    }

    override fun invoke(project: Project, elements: Array<out PsiElement>, dataContext: DataContext) {
        val string = elements.singleOrNull() as? StringLiteralExpression ?: return

        rename(string, project, CommonDataKeys.EDITOR.getData(dataContext), dataContext)
    }

    /** Opens the rename dialog on [string], or renames it straight away to a name the caller already gave. */
    private fun rename(string: StringLiteralExpression, project: Project, editor: Editor?, dataContext: DataContext) {
        PsiElementRenameHandler.rename(string, project, string, editor, PsiElementRenameHandler.DEFAULT_NAME.getData(dataContext))
    }
}

/**
 * The string at the caret of the editor [dataContext] is for, when it holds the
 * name of a condition, template or ability.
 */
fun nameStringAtCaret(dataContext: DataContext): StringLiteralExpression? {
    val editor = CommonDataKeys.EDITOR.getData(dataContext) ?: return null
    val file = CommonDataKeys.PSI_FILE.getData(dataContext) ?: return null

    return nameStringAt(file, editor.caretModel.offset)
}

/** The string at [offset] of [file], when it holds the name of a condition, template or ability. */
fun nameStringAt(file: PsiFile, offset: Int): StringLiteralExpression? {
    val string = PsiTreeUtil.getParentOfType(file.findElementAt(offset), StringLiteralExpression::class.java, false)
        ?: return null

    return string.takeIf { WarrantConstructs.declaredBy(it) != null }
}

/**
 * Names a PHP string that holds the name of a condition, template or ability
 * by that name, and says what it names, where the rename dialog and the usage
 * views show it.
 */
class WarrantNameStringDescriptionProvider : ElementDescriptionProvider {

    override fun getElementDescription(element: PsiElement, location: ElementDescriptionLocation): String? {
        if (element !is StringLiteralExpression) return null

        val construct = WarrantConstructs.declaredBy(element) ?: return null

        return when (location) {
            is UsageViewTypeLocation -> construct.kind.label
            is UsageViewShortNameLocation, is UsageViewLongNameLocation -> element.contents
            else -> null
        }
    }
}

/**
 * Checks a new name for a condition, template or ability before a rename takes
 * it: rule text must be able to write it, and no schema that has the
 * declaration may have another of its kind by that name. A method's new name is
 * also checked as a PHP name, since this check stands in for PHP's own.
 */
class WarrantRenameInputValidator : RenameInputValidatorEx {

    private var error: String? = null

    /** The names taken from the last declaration asked about, kept while its dialog is open. */
    @Volatile
    private var takenFrom: Pair<WarrantConstruct, Set<String>>? = null

    override fun getPattern(): ElementPattern<out PsiElement> =
        PlatformPatterns.psiElement().with(object : PatternCondition<PsiElement>("declares a Warrant name") {
            override fun accepts(element: PsiElement, context: ProcessingContext?): Boolean =
                WarrantConstructs.declaredBy(element) != null
        })

    override fun isInputValid(newName: String, element: PsiElement, context: ProcessingContext): Boolean {
        error = errorFor(newName, element)

        return error == null
    }

    override fun getErrorMessage(newName: String, project: Project): String? = error

    private fun errorFor(newName: String, element: PsiElement): String? {
        val construct = WarrantConstructs.declaredBy(element) ?: return null

        if (element is Method && !PHP_NAME.matches(newName)) return "'$newName' is not a valid PHP method name"

        val name = construct.nameAfterRename(newName)

        if (!WarrantConstructs.isName(name)) return "Rule text cannot write '$name' as a name"

        if (name in takenNames(construct)) return "A schema that has this ${construct.kind.wireValue} already has one named '$name'"

        return null
    }

    /**
     * The names taken from [construct]. The dialog checks a name as it is typed,
     * on the UI thread, so the server is asked once in the background and no
     * name counts as taken until it has answered.
     */
    private fun takenNames(construct: WarrantConstruct): Set<String> {
        takenFrom?.takeIf { it.first == construct }?.let { return it.second }

        val application = ApplicationManager.getApplication()
        val ask = {
            val taken = WarrantServerQueries.getInstance(construct.nameDeclaredBy.project)
                .usagesOf(construct.file, construct.member)?.taken.orEmpty().toSet()
            takenFrom = construct to taken
        }

        if (application.isDispatchThread && !application.isUnitTestMode) {
            takenFrom = construct to emptySet()
            application.executeOnPooledThread(ask)
        } else {
            ask()
        }

        return takenFrom?.second.orEmpty()
    }

    private companion object {
        val PHP_NAME = Regex("[A-Za-z_\\x80-\\uffff][A-Za-z0-9_\\x80-\\uffff]*")
    }
}
