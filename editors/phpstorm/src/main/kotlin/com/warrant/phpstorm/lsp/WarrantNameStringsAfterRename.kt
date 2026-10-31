package com.warrant.phpstorm.lsp

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.command.WriteCommandAction
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.ui.DialogWrapper
import com.intellij.psi.ElementManipulators
import com.intellij.psi.PsiElement
import com.intellij.psi.SmartPointerManager
import com.intellij.psi.SmartPsiElementPointer
import com.intellij.psi.search.GlobalSearchScope
import com.intellij.psi.search.PsiSearchHelper
import com.intellij.psi.util.PsiTreeUtil
import com.intellij.refactoring.listeners.RefactoringEventData
import com.intellij.refactoring.listeners.RefactoringEventListener
import com.intellij.ui.CheckBoxList
import com.intellij.ui.components.JBLabel
import com.intellij.ui.components.JBScrollPane
import com.intellij.util.ui.JBUI
import com.jetbrains.php.lang.psi.elements.FunctionReference
import com.jetbrains.php.lang.psi.elements.Method
import com.jetbrains.php.lang.psi.elements.MethodReference
import com.jetbrains.php.lang.psi.elements.ParameterList
import com.jetbrains.php.lang.psi.elements.StringLiteralExpression
import com.warrant.phpstorm.WarrantNames
import java.awt.BorderLayout
import javax.swing.JComponent
import javax.swing.JPanel

/**
 * After a condition is renamed, offers the PHP strings that may name it but that
 * no schema ties to it: the names given to Warrant's builders, as in
 * `->if('is_owner')` or `Build::check('is_owner')`. Which schema such a string
 * means is not written anywhere, so each is offered unticked, and only the ones
 * ticked are changed, in a step of their own that undoes apart from the rename.
 */
class WarrantNameStringsAfterRename(private val project: Project) : RefactoringEventListener {

    /** The rename under way: the condition renamed, and its name before. */
    private var renaming: Pair<SmartPsiElementPointer<PsiElement>, String>? = null

    override fun refactoringStarted(refactoringId: String, beforeData: RefactoringEventData?) {
        renaming = null

        if (refactoringId != RENAME) return

        val element = beforeData?.getUserData(RefactoringEventData.PSI_ELEMENT_KEY) ?: return
        if (WarrantConstructs.declaredBy(element)?.kind != WarrantKind.CONDITION) return

        renaming = SmartPointerManager.createPointer(element) to (nameOf(element) ?: return)
    }

    override fun refactoringDone(refactoringId: String, afterData: RefactoringEventData?) {
        val (pointer, oldName) = renaming ?: return
        renaming = null

        if (refactoringId != RENAME) return

        val newName = (pointer.element ?: afterData?.getUserData(RefactoringEventData.PSI_ELEMENT_KEY))?.let(::nameOf) ?: return

        if (newName == oldName) return

        val strings = stringsNaming(project, oldName)

        if (strings.isEmpty() || ApplicationManager.getApplication().isUnitTestMode) return

        ApplicationManager.getApplication().invokeLater({ offerStrings(project, strings, oldName, newName) }, project.disposed)
    }

    private fun nameOf(element: PsiElement): String? = when (element) {
        is Method -> WarrantNames.ruleTextNameOf(element.name)
        is StringLiteralExpression -> element.contents
        else -> null
    }

    companion object {
        private const val RENAME = "refactoring.rename"

        /** Warrant's builder methods taking a condition's name first. */
        private val CONDITION_METHODS = setOf("if", "andIf", "orIf", "ifNot", "andIfNot", "orIfNot")

        /**
         * The project's PHP strings whose whole text is [name] and that are passed
         * first to a builder call that takes a condition's name there.
         */
        fun stringsNaming(project: Project, name: String): List<StringLiteralExpression> {
            val word = name.split(Regex("[^A-Za-z0-9_]+")).maxByOrNull { it.length }?.takeIf { it.isNotEmpty() } ?: return emptyList()
            val found = mutableListOf<StringLiteralExpression>()

            PsiSearchHelper.getInstance(project).processAllFilesWithWordInLiterals(word, GlobalSearchScope.projectScope(project)) { file ->
                PsiTreeUtil.findChildrenOfType(file, StringLiteralExpression::class.java)
                    .filterTo(found) { it.contents == name && takesConditionName(it) }
                true
            }

            return found
        }

        private fun takesConditionName(string: StringLiteralExpression): Boolean {
            val arguments = string.parent as? ParameterList ?: return false
            val call = arguments.parent as? FunctionReference ?: return false

            if (arguments.parameters.firstOrNull() !== string) return false

            val name = call.name ?: return false
            val method = call as? MethodReference ?: return false

            return name in CONDITION_METHODS && !method.isStatic || name == "check" && isBuild(method)
        }

        private fun isBuild(call: MethodReference): Boolean =
            call.isStatic && call.classReference?.name == "Build"
    }
}

/**
 * Offers [strings], which may name the condition renamed from [oldName] to
 * [newName] but that nothing ties to it, each unticked, and changes the ones
 * ticked to [newName], in a step of its own that undoes apart from the rename.
 */
private fun offerStrings(project: Project, strings: List<StringLiteralExpression>, oldName: String, newName: String) {
    val chosen = WarrantNameStringsDialog(project, strings, oldName, newName).takeIf { it.showAndGet() }?.chosen()

    if (chosen.isNullOrEmpty()) return

    WriteCommandAction.writeCommandAction(project)
        .withName("Rename Warrant Names in Strings")
        .run<RuntimeException> {
            for (string in chosen.filter { it.isValid && it.contents == oldName }) {
                ElementManipulators.handleContentChange(string, newName)
            }
        }
}

/**
 * Lists strings that may name a renamed condition, each with its file, line and
 * the line's code, all unticked.
 */
private class WarrantNameStringsDialog(
    project: Project,
    private val strings: List<StringLiteralExpression>,
    oldName: String,
    newName: String,
) : DialogWrapper(project) {

    private val list = CheckBoxList<StringLiteralExpression>()

    private val message =
        "These strings may name the condition '$oldName', but nothing ties them to it. " +
            "Tick the ones that mean the one renamed, to change them to '$newName'."

    init {
        title = "Warrant Names in Strings"

        for (string in strings) {
            list.addItem(string, describe(string), false)
        }

        init()
    }

    fun chosen(): List<StringLiteralExpression> = strings.filter { list.isItemSelected(it) }

    override fun createCenterPanel(): JComponent = JPanel(BorderLayout(0, JBUI.scale(8))).apply {
        add(JBLabel(message), BorderLayout.NORTH)
        add(JBScrollPane(list), BorderLayout.CENTER)
        preferredSize = JBUI.size(640, 320)
    }

    private fun describe(string: StringLiteralExpression): String {
        val file = string.containingFile.virtualFile
        val document = file?.let { FileDocumentManager.getInstance().getDocument(it) } ?: return string.text
        val line = document.getLineNumber(string.textRange.startOffset)
        val code = document.charsSequence.subSequence(document.getLineStartOffset(line), document.getLineEndOffset(line)).trim()

        return "${file.name}:${line + 1}  $code"
    }
}
