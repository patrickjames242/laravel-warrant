package com.warrant.phpstorm.lsp

import com.intellij.find.findUsages.FindUsagesHandler
import com.intellij.find.findUsages.FindUsagesHandlerFactory
import com.intellij.find.findUsages.PsiElement2UsageTargetAdapter
import com.intellij.lang.injection.InjectedLanguageManager
import com.intellij.openapi.actionSystem.CommonDataKeys
import com.intellij.openapi.actionSystem.DataContext
import com.intellij.openapi.command.WriteCommandAction
import com.intellij.openapi.editor.Document
import com.intellij.openapi.editor.Editor
import com.intellij.openapi.fileEditor.FileDocumentManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.ui.InputValidatorEx
import com.intellij.openapi.ui.Messages
import com.intellij.openapi.util.TextRange
import com.intellij.psi.ElementDescriptionLocation
import com.intellij.psi.ElementDescriptionProvider
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.PsiElement
import com.intellij.psi.PsiFile
import com.intellij.psi.util.CachedValueProvider
import com.intellij.psi.util.CachedValuesManager
import com.intellij.psi.util.PsiModificationTracker
import com.intellij.refactoring.rename.PsiElementRenameHandler
import com.intellij.refactoring.rename.RenameHandler
import com.intellij.usageView.UsageViewLongNameLocation
import com.intellij.usageView.UsageViewShortNameLocation
import com.intellij.usageView.UsageViewTypeLocation
import com.intellij.usages.UsageTarget
import com.intellij.usages.UsageTargetProvider
import com.warrant.phpstorm.WarrantName
import com.warrant.phpstorm.WarrantNames
import org.eclipse.lsp4j.Location

/**
 * The names `as` gives frames, which no PHP member declares: `d` in
 * `check(... as d)` and in each `@column d.id` that refers to that frame. Where
 * one is written comes from the language server (`warrant/occurrences`), and it
 * is found and renamed in the one rule that gives it.
 */
object WarrantFrameNames {

    /**
     * Every place the frame name [name] is written; null when [name] names no
     * frame an `as` gives, or no server is running. Kept until the code changes.
     */
    fun occurrencesOf(name: WarrantName): WarrantOccurrences? =
        CachedValuesManager.getCachedValue(name) {
            CachedValueProvider.Result.create(ask(name), PsiModificationTracker.getInstance(name.project))
        }

    private fun ask(name: WarrantName): WarrantOccurrences? {
        val injection = InjectedLanguageManager.getInstance(name.project)
        val hostFile = injection.getTopLevelFile(name)?.virtualFile ?: return null
        val hostOffset = injection.injectedToHost(name, name.textRange.startOffset)

        return WarrantServerQueries.getInstance(name.project).occurrencesAt(hostFile, hostOffset)
            ?.takeIf { it.kind == "frame" }
    }

    /** The frame name at the caret of the editor [dataContext] is for. */
    fun atCaret(dataContext: DataContext): WarrantName? {
        val editor = CommonDataKeys.EDITOR.getData(dataContext) ?: return null
        val file = CommonDataKeys.PSI_FILE.getData(dataContext) ?: return null

        return at(file, editor.caretModel.offset)
    }

    /** The frame name at [offset] of [file]. */
    fun at(file: PsiFile, offset: Int): WarrantName? = nameAt(file, offset)?.takeIf { occurrencesOf(it) != null }

    /** Renames the frame name [name] and every other place it is written to [newName], in one step. */
    fun rename(project: Project, name: WarrantName, newName: String) {
        val found = occurrencesOf(name) ?: return
        val queries = WarrantServerQueries.getInstance(project)
        val edits = mutableMapOf<Document, MutableList<TextRange>>()

        for (occurrence in found.occurrences.orEmpty()) {
            val (document, range) = rangeOf(queries, occurrence) ?: continue
            edits.getOrPut(document) { mutableListOf() } += range
        }

        WriteCommandAction.writeCommandAction(project)
            .withName("Rename Warrant Frame")
            .run<RuntimeException> {
                for ((document, ranges) in edits) {
                    for (range in ranges.distinct().sortedByDescending { it.startOffset }) {
                        document.replaceString(range.startOffset, range.endOffset, newName)
                    }
                }

                PsiDocumentManager.getInstance(project).commitAllDocuments()
            }
    }

    /** The document and range [location], which the server gave, is. */
    private fun rangeOf(queries: WarrantServerQueries, location: Location): Pair<Document, TextRange>? {
        val file = queries.fileOf(location.uri) ?: return null
        val document = FileDocumentManager.getInstance().getDocument(file) ?: return null
        val start = offsetOf(document, location.range.start.line, location.range.start.character) ?: return null
        val end = offsetOf(document, location.range.end.line, location.range.end.character) ?: return null

        return document to TextRange(start, end)
    }

    private fun offsetOf(document: Document, line: Int, character: Int): Int? {
        if (line < 0 || line >= document.lineCount) return null

        val offset = document.getLineStartOffset(line) + character

        return if (offset > document.getLineEndOffset(line)) null else offset
    }
}

/**
 * Renames a frame name from where rule text writes it ([WarrantFrameNames.rename]),
 * asking for the new name as rule text writes it.
 */
class WarrantFrameRenameHandler : RenameHandler {

    override fun isAvailableOnDataContext(dataContext: DataContext): Boolean = WarrantFrameNames.atCaret(dataContext) != null

    override fun invoke(project: Project, editor: Editor?, file: PsiFile?, dataContext: DataContext) {
        val name = WarrantFrameNames.atCaret(dataContext) ?: return
        val validator = object : InputValidatorEx {
            override fun getErrorText(inputString: String): String? =
                if (WarrantNames.isName(inputString)) null else "Rule text cannot write '$inputString' as a name"

            override fun checkInput(inputString: String): Boolean = getErrorText(inputString) == null

            override fun canClose(inputString: String): Boolean = checkInput(inputString)
        }

        val newName = PsiElementRenameHandler.DEFAULT_NAME.getData(dataContext)
            ?: Messages.showInputDialog(project, "Rename the frame '${name.text}' and its uses to:", "Rename", null, name.text, validator)
            ?: return

        if (newName == name.text || validator.getErrorText(newName) != null) return

        WarrantFrameNames.rename(project, name, newName)
    }

    override fun invoke(project: Project, elements: Array<out PsiElement>, dataContext: DataContext) {
        invoke(project, CommonDataKeys.EDITOR.getData(dataContext), CommonDataKeys.PSI_FILE.getData(dataContext), dataContext)
    }
}

/** Makes the frame name at the caret what Find Usages searches for. */
class WarrantFrameUsageTargetProvider : UsageTargetProvider {

    override fun getTargets(editor: Editor, file: PsiFile): Array<UsageTarget>? {
        val name = WarrantFrameNames.at(file, editor.caretModel.offset) ?: return null

        return arrayOf(PsiElement2UsageTargetAdapter(name, true))
    }
}

/** Finds the usages of a frame name, which [WarrantReferencesSearcher] gives. */
class WarrantFrameFindUsagesHandlerFactory : FindUsagesHandlerFactory() {

    override fun canFindUsages(element: PsiElement): Boolean =
        element is WarrantName && WarrantFrameNames.occurrencesOf(element) != null

    override fun createFindUsagesHandler(element: PsiElement, forHighlightUsages: Boolean): FindUsagesHandler =
        object : FindUsagesHandler(element) {}
}

/** Names a frame name, and says it is one, where the usage views show it. */
class WarrantFrameDescriptionProvider : ElementDescriptionProvider {

    override fun getElementDescription(element: PsiElement, location: ElementDescriptionLocation): String? {
        if (element !is WarrantName || WarrantFrameNames.occurrencesOf(element) == null) return null

        return when (location) {
            is UsageViewTypeLocation -> "Warrant frame"
            is UsageViewShortNameLocation, is UsageViewLongNameLocation -> element.text
            else -> null
        }
    }
}
