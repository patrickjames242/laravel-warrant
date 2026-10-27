package com.warrant.phpstorm

import com.intellij.codeInsight.editorActions.enter.EnterHandlerDelegate
import com.intellij.codeInsight.editorActions.enter.EnterHandlerDelegateAdapter
import com.intellij.injected.editor.EditorWindow
import com.intellij.openapi.actionSystem.DataContext
import com.intellij.openapi.editor.Editor
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.PsiFile

/**
 * Indents the line Enter opens in Warrant, in a `.warrant` file or inside PHP,
 * by [WarrantIndentation]: the rules the formatter lays text out with. When
 * Enter splits a bracket pair, so the closing bracket ends up on the line
 * below, that line is indented too.
 */
class WarrantEnterHandler : EnterHandlerDelegateAdapter() {

    override fun postProcessEnter(file: PsiFile, editor: Editor, dataContext: DataContext): EnterHandlerDelegate.Result {
        val host = (editor as? EditorWindow)?.delegate ?: editor
        val project = host.project ?: return EnterHandlerDelegate.Result.Continue
        val documents = PsiDocumentManager.getInstance(project)
        documents.commitDocument(host.document)

        val hostFile = documents.getPsiFile(host.document) ?: return EnterHandlerDelegate.Result.Continue
        val document = host.document
        val line = document.getLineNumber(host.caretModel.offset)
        val indentation = WarrantIndentation.at(project, hostFile, document, host.caretModel.offset)
            ?: return EnterHandlerDelegate.Result.Continue

        val caret = indentation.indentLineAt(document.getLineStartOffset(line))

        if (line + 1 < document.lineCount && document.getLineEndOffset(line) == caret) {
            val next = document.getLineStartOffset(line + 1)
            val firstChar = document.charsSequence.subSequence(next, document.getLineEndOffset(line + 1)).trimStart().firstOrNull()

            if (firstChar == '}' || firstChar == ')') {
                indentation.indentLineAt(next)
            }
        }

        host.caretModel.moveToOffset(caret)
        documents.commitDocument(document)

        return EnterHandlerDelegate.Result.Stop
    }
}
