package com.warrant.phpstorm

import com.intellij.codeInsight.editorActions.TypedHandlerDelegate
import com.intellij.injected.editor.EditorWindow
import com.intellij.openapi.editor.Editor
import com.intellij.openapi.project.Project
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.PsiFile

/**
 * Moves a `}` or `)` typed at the start of a line back to the indentation of
 * the line its opening bracket is on, as the formatter writes it, in a
 * `.warrant` file or inside PHP.
 */
class WarrantTypedHandler : TypedHandlerDelegate() {

    override fun charTyped(c: Char, project: Project, editor: Editor, file: PsiFile): Result {
        if (c != '}' && c != ')') return Result.CONTINUE

        val host = (editor as? EditorWindow)?.delegate ?: editor
        val document = host.document
        val typedAt = host.caretModel.offset - 1
        if (typedAt < 0 || document.charsSequence[typedAt] != c) return Result.CONTINUE

        val lineStart = document.getLineStartOffset(document.getLineNumber(typedAt))
        if (document.charsSequence.subSequence(lineStart, typedAt).isNotBlank()) return Result.CONTINUE

        val documents = PsiDocumentManager.getInstance(project)
        documents.commitDocument(document)
        val hostFile = documents.getPsiFile(document) ?: return Result.CONTINUE
        val indentation = WarrantIndentation.at(project, hostFile, document, typedAt) ?: return Result.CONTINUE

        val textStart = indentation.indentLineAt(lineStart)
        host.caretModel.moveToOffset(textStart + 1)
        documents.commitDocument(document)

        return Result.STOP
    }
}
