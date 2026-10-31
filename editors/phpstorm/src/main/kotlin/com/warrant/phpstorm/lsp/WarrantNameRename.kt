package com.warrant.phpstorm.lsp

import com.intellij.lang.LanguageRefactoringSupport
import com.intellij.lang.injection.InjectedLanguageManager
import com.intellij.lang.refactoring.RefactoringSupportProvider
import com.intellij.openapi.actionSystem.CommonDataKeys
import com.intellij.openapi.actionSystem.DataContext
import com.intellij.openapi.editor.Editor
import com.intellij.openapi.project.Project
import com.intellij.openapi.ui.InputValidatorEx
import com.intellij.openapi.ui.Messages
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiFile
import com.intellij.refactoring.RefactoringActionHandler
import com.intellij.refactoring.changeSignature.ChangeSignatureHandler
import com.intellij.refactoring.rename.PsiElementRenameHandler
import com.intellij.refactoring.rename.RenameHandler
import com.intellij.refactoring.rename.RenameProcessor
import com.intellij.refactoring.util.CommonRefactoringUtil
import com.intellij.util.ProcessingContext
import com.jetbrains.php.lang.PhpLanguage
import com.jetbrains.php.lang.psi.elements.Method
import com.warrant.phpstorm.WarrantLanguage
import com.warrant.phpstorm.WarrantNames

/**
 * Renames a condition or template from a name in rule text, by the name rule
 * text writes: it asks for the new name in snake case, as `is_author`, and
 * renames the method to it in camel case, `isAuthor`, which renames every use.
 *
 * PhpStorm's own rename of a method, in place at the caret, would write the
 * method's camel case name into the rule text there, so [WarrantMethodRefactoringSupport]
 * turns it off in rule text and this handler takes its place.
 */
class WarrantNameRenameHandler : RenameHandler {

    override fun isAvailableOnDataContext(dataContext: DataContext): Boolean = methodAtCaret(dataContext) != null

    override fun invoke(project: Project, editor: Editor?, file: PsiFile?, dataContext: DataContext) {
        val method = methodAtCaret(dataContext) ?: return
        val current = WarrantNames.ruleTextNameOf(method.name)
        val validator = NameValidator(method)
        val given = PsiElementRenameHandler.DEFAULT_NAME.getData(dataContext)

        val newName = given ?: Messages.showInputDialog(
            project,
            "Rename the ${WarrantConstructs.kindOf(method)?.wireValue ?: "name"} '$current', its method and its uses to:",
            "Rename",
            null,
            current,
            validator,
        ) ?: return

        if (newName == current) return

        val methodName = WarrantNames.methodNameFor(newName)
        val error = validator.getErrorText(newName)

        if (error != null || methodName == null) {
            CommonRefactoringUtil.showErrorHint(project, editor, error ?: "No method name gives the name '$newName'", "Rename", null)
            return
        }

        RenameProcessor(project, method, methodName, false, false).run()
    }

    override fun invoke(project: Project, elements: Array<out PsiElement>, dataContext: DataContext) {
        invoke(project, CommonDataKeys.EDITOR.getData(dataContext), CommonDataKeys.PSI_FILE.getData(dataContext), dataContext)
    }

    /**
     * Checks a new name as rule text writes it: it must be a name rule text can
     * write, one some method name gives ([WarrantNames.methodNameFor]), and free,
     * as [WarrantRenameInputValidator] checks the method's new name.
     */
    private class NameValidator(private val method: Method) : InputValidatorEx {

        private val methodNames = WarrantRenameInputValidator()

        override fun getErrorText(inputString: String): String? {
            if (!WarrantNames.isName(inputString)) return "Rule text cannot write '$inputString' as a name"

            val methodName = WarrantNames.methodNameFor(inputString)
                ?: return "No method name gives the name '$inputString'; give the attribute a key to use it"

            return if (methodNames.isInputValid(methodName, method, ProcessingContext())) null
            else methodNames.getErrorMessage(methodName, method.project)
        }

        override fun checkInput(inputString: String): Boolean = getErrorText(inputString) == null

        override fun canClose(inputString: String): Boolean = checkInput(inputString)
    }

    private companion object {
        /**
         * The condition or template method the name at the caret names, when
         * the name's own text is its snake case, so it renames by a new method name.
         */
        fun methodAtCaret(dataContext: DataContext): Method? {
            val editor = CommonDataKeys.EDITOR.getData(dataContext) ?: return null
            val file = CommonDataKeys.PSI_FILE.getData(dataContext) ?: return null
            val name = nameAt(file, editor.caretModel.offset) ?: return null
            val method = name.reference?.resolve() as? Method ?: return null

            return method.takeIf { WarrantConstructs.declaredBy(it) != null }
        }
    }
}

/**
 * PHP's refactoring support for a condition or template method, but with
 * renaming in place turned off when it is started from rule text, so
 * [WarrantNameRenameHandler] renames it by the name rule text writes. Everything
 * else is PHP's own.
 */
class WarrantMethodRefactoringSupport : RefactoringSupportProvider() {

    /** The PHP plugin's support, which this stands in front of. */
    private val php: RefactoringSupportProvider? by lazy {
        LanguageRefactoringSupport.INSTANCE.allForLanguage(PhpLanguage.INSTANCE).firstOrNull { it !is WarrantMethodRefactoringSupport }
    }

    override fun isAvailable(context: PsiElement): Boolean =
        context is Method && WarrantConstructs.declaredBy(context) != null && php?.isAvailable(context) == true

    override fun isMemberInplaceRenameAvailable(element: PsiElement, context: PsiElement?): Boolean =
        !isInRuleText(context) && php?.isMemberInplaceRenameAvailable(element, context) == true

    override fun isInplaceRenameAvailable(element: PsiElement, context: PsiElement?): Boolean =
        !isInRuleText(context) && php?.isInplaceRenameAvailable(element, context) == true

    override fun isSafeDeleteAvailable(element: PsiElement): Boolean = php?.isSafeDeleteAvailable(element) == true

    override fun isInplaceIntroduceAvailable(element: PsiElement, context: PsiElement?): Boolean =
        php?.isInplaceIntroduceAvailable(element, context) == true

    override fun getIntroduceVariableHandler(): RefactoringActionHandler? = php?.introduceVariableHandler

    override fun getIntroduceVariableHandler(element: PsiElement?): RefactoringActionHandler? = php?.getIntroduceVariableHandler(element)

    override fun getExtractMethodHandler(): RefactoringActionHandler? = php?.extractMethodHandler

    override fun getIntroduceConstantHandler(): RefactoringActionHandler? = php?.introduceConstantHandler

    override fun getIntroduceFieldHandler(): RefactoringActionHandler? = php?.introduceFieldHandler

    override fun getIntroduceParameterHandler(): RefactoringActionHandler? = php?.introduceParameterHandler

    override fun getIntroduceFunctionalParameterHandler(): RefactoringActionHandler? = php?.introduceFunctionalParameterHandler

    override fun getIntroduceFunctionalVariableHandler(): RefactoringActionHandler? = php?.introduceFunctionalVariableHandler

    override fun getPullUpHandler(): RefactoringActionHandler? = php?.pullUpHandler

    override fun getPushDownHandler(): RefactoringActionHandler? = php?.pushDownHandler

    override fun getExtractInterfaceHandler(): RefactoringActionHandler? = php?.extractInterfaceHandler

    override fun getExtractModuleHandler(): RefactoringActionHandler? = php?.extractModuleHandler

    override fun getExtractSuperClassHandler(): RefactoringActionHandler? = php?.extractSuperClassHandler

    override fun getChangeSignatureHandler(): ChangeSignatureHandler? = php?.changeSignatureHandler

    override fun getExtractClassHandler(): RefactoringActionHandler? = php?.extractClassHandler

    /** Whether [context], what is at the caret, is in rule text. */
    private fun isInRuleText(context: PsiElement?): Boolean {
        if (context == null) return false
        if (context.language == WarrantLanguage) return true

        val file = context.containingFile ?: return false

        return InjectedLanguageManager.getInstance(context.project)
            .findInjectedElementAt(file, context.textRange.startOffset)?.language == WarrantLanguage
    }
}
