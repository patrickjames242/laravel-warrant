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
import java.awt.BorderLayout
import javax.swing.JComponent
import javax.swing.JPanel

/**
 * After a condition or ability is renamed, offers the PHP strings that may name
 * it but that no schema ties to it: the names given to Warrant's builders, as in
 * `->if('is_owner')` or `Build::can('view')`, and to Laravel's ability checks,
 * as in `$user->can('view', $post)`. Which schema such a string means is not
 * written anywhere, so each is offered unticked, and only the ones ticked are
 * changed, in a step of their own that undoes apart from the rename.
 */
class WarrantNameStringsAfterRename(private val project: Project) : RefactoringEventListener {

    /** The rename under way: what is renamed, its kind, and its name before. */
    private var renaming: Triple<SmartPsiElementPointer<PsiElement>, WarrantKind, String>? = null

    override fun refactoringStarted(refactoringId: String, beforeData: RefactoringEventData?) {
        renaming = null

        if (refactoringId != RENAME) return

        val element = beforeData?.getUserData(RefactoringEventData.PSI_ELEMENT_KEY) ?: return
        val construct = WarrantConstructs.declaredBy(element) ?: return

        renaming = Triple(SmartPointerManager.createPointer(element), construct.kind, nameOf(element) ?: return)
    }

    override fun refactoringDone(refactoringId: String, afterData: RefactoringEventData?) {
        val (pointer, kind, oldName) = renaming ?: return
        renaming = null

        if (refactoringId != RENAME) return

        val newName = (pointer.element ?: afterData?.getUserData(RefactoringEventData.PSI_ELEMENT_KEY))?.let(::nameOf) ?: return

        if (newName == oldName) return

        val strings = stringsNaming(project, kind, oldName)

        if (strings.isEmpty() || ApplicationManager.getApplication().isUnitTestMode) return

        ApplicationManager.getApplication().invokeLater({ offer(strings, kind, oldName, newName) }, project.disposed)
    }

    private fun nameOf(element: PsiElement): String? = when (element) {
        is Method -> WarrantConstructs.snakeCase(element.name)
        is StringLiteralExpression -> element.contents
        else -> null
    }

    private fun offer(strings: List<StringLiteralExpression>, kind: WarrantKind, oldName: String, newName: String) {
        val chosen = WarrantNameStringsDialog(project, strings, kind, oldName, newName).takeIf { it.showAndGet() }?.chosen()

        if (chosen.isNullOrEmpty()) return

        WriteCommandAction.writeCommandAction(project)
            .withName("Rename Warrant Names in Strings")
            .run<RuntimeException> {
                for (string in chosen.filter { it.isValid && it.contents == oldName }) {
                    ElementManipulators.handleContentChange(string, newName)
                }
            }
    }

    companion object {
        private const val RENAME = "refactoring.rename"

        /** Warrant's builder methods taking a condition's name first. */
        private val CONDITION_METHODS = setOf("if", "andIf", "orIf", "ifNot", "andIfNot", "orIfNot")

        /** Warrant's builder methods taking an ability's name first. */
        private val ABILITY_METHODS = setOf("ifCan", "andIfCan", "orIfCan")

        /** Laravel's methods taking an ability's name first: the user's, a policy's, a controller's. */
        private val LARAVEL_ABILITY_METHODS = setOf("can", "cannot", "cant", "authorize")

        /** The `Gate` facade's methods taking an ability's name first. */
        private val GATE_METHODS = setOf("allows", "denies", "check", "any", "none", "inspect", "authorize")

        /**
         * The project's PHP strings whose whole text is [name] and that are passed
         * first to a call that takes a name of [kind] there.
         */
        fun stringsNaming(project: Project, kind: WarrantKind, name: String): List<StringLiteralExpression> {
            if (kind == WarrantKind.TEMPLATE) return emptyList()

            val word = name.split(Regex("[^A-Za-z0-9_]+")).maxByOrNull { it.length }?.takeIf { it.isNotEmpty() } ?: return emptyList()
            val found = mutableListOf<StringLiteralExpression>()

            PsiSearchHelper.getInstance(project).processAllFilesWithWordInLiterals(word, GlobalSearchScope.projectScope(project)) { file ->
                PsiTreeUtil.findChildrenOfType(file, StringLiteralExpression::class.java)
                    .filterTo(found) { it.contents == name && takesName(it, kind) }
                true
            }

            return found
        }

        private fun takesName(string: StringLiteralExpression, kind: WarrantKind): Boolean {
            val arguments = string.parent as? ParameterList ?: return false
            val call = arguments.parent as? FunctionReference ?: return false

            if (arguments.parameters.firstOrNull() !== string) return false

            val name = call.name ?: return false
            val method = call as? MethodReference

            return when (kind) {
                WarrantKind.CONDITION ->
                    method != null && (name in CONDITION_METHODS && !method.isStatic || name == "check" && isBuild(method))
                WarrantKind.ABILITY -> when {
                    method == null -> false
                    method.isStatic -> name == "can" && isBuild(method) || name in GATE_METHODS && isGate(method)
                    else -> name in ABILITY_METHODS || name in LARAVEL_ABILITY_METHODS
                }
                WarrantKind.TEMPLATE -> false
            }
        }

        private fun isBuild(call: MethodReference): Boolean =
            call.isStatic && call.classReference?.name == "Build"

        private fun isGate(call: MethodReference): Boolean =
            call.isStatic && call.classReference?.name == "Gate"
    }
}

/**
 * Lists strings that may name a renamed condition or ability, each with its file,
 * line and the line's code, all unticked.
 */
private class WarrantNameStringsDialog(
    project: Project,
    private val strings: List<StringLiteralExpression>,
    kind: WarrantKind,
    oldName: String,
    newName: String,
) : DialogWrapper(project) {

    private val list = CheckBoxList<StringLiteralExpression>()

    private val message =
        "These strings name '$oldName' where no schema says which ${kind.wireValue} they mean. " +
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
