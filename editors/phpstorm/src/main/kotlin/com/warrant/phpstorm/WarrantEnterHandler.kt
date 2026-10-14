package com.warrant.phpstorm

import com.intellij.application.options.CodeStyle
import com.intellij.codeInsight.editorActions.enter.EnterHandlerDelegate
import com.intellij.codeInsight.editorActions.enter.EnterHandlerDelegateAdapter
import com.intellij.injected.editor.DocumentWindow
import com.intellij.injected.editor.EditorWindow
import com.intellij.lang.injection.InjectedLanguageManager
import com.intellij.openapi.actionSystem.DataContext
import com.intellij.openapi.editor.Editor
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.PsiFile

/**
 * Indents the line Enter opens in Warrant inside PHP: one level deeper than the
 * Warrant's top-level rules for each `{` still open before the caret.
 *
 * In a `.warrant` file [WarrantFormattingModelBuilder] does this, but inside PHP
 * the IDE asks PHP where a new line goes, and PHP keeps the previous line's
 * indentation inside a string. So after Enter this sets the new line's
 * indentation itself.
 *
 * The top-level rules stand at the indentation of the least-indented line of the
 * Warrant: in a heredoc, the heredoc's own indentation. A line the Warrant does
 * not start, such as the first line of a string opened mid-line, does not count,
 * and when no line counts the new line is left as PHP indented it.
 */
class WarrantEnterHandler : EnterHandlerDelegateAdapter() {

    override fun postProcessEnter(file: PsiFile, editor: Editor, dataContext: DataContext): EnterHandlerDelegate.Result {
        val host = (editor as? EditorWindow)?.delegate ?: editor
        val project = host.project ?: return EnterHandlerDelegate.Result.Continue
        val documents = PsiDocumentManager.getInstance(project)
        documents.commitDocument(host.document)

        val hostFile = documents.getPsiFile(host.document) ?: return EnterHandlerDelegate.Result.Continue
        if (hostFile is WarrantFile) return EnterHandlerDelegate.Result.Continue

        val offset = host.caretModel.offset
        val warrant = InjectedLanguageManager.getInstance(project).findInjectedElementAt(hostFile, offset)?.containingFile as? WarrantFile
            ?: return EnterHandlerDelegate.Result.Continue
        val window = documents.getDocument(warrant) as? DocumentWindow ?: return EnterHandlerDelegate.Result.Continue

        val options = CodeStyle.getIndentOptions(hostFile)
        val base = baseIndent(window, host.document.charsSequence, options.TAB_SIZE) ?: return EnterHandlerDelegate.Result.Continue
        val width = base + openBraces(warrant.text, window.hostToInjected(offset)) * options.INDENT_SIZE

        val line = host.document.getLineNumber(offset)
        val lineStart = host.document.getLineStartOffset(line)
        val text = host.document.charsSequence
        var indentEnd = lineStart
        while (indentEnd < host.document.getLineEndOffset(line) && (text[indentEnd] == ' ' || text[indentEnd] == '\t')) indentEnd++

        val indent = if (options.USE_TAB_CHARACTER) "\t".repeat(width / options.TAB_SIZE) + " ".repeat(width % options.TAB_SIZE) else " ".repeat(width)
        host.document.replaceString(lineStart, indentEnd, indent)
        host.caretModel.moveToOffset(lineStart + indent.length)

        return EnterHandlerDelegate.Result.Stop
    }

    /**
     * The width of the least-indented line of the Warrant in $window that holds
     * more than whitespace, among the lines it starts; measured in the host's
     * $text. Null when there is none.
     */
    private fun baseIndent(window: DocumentWindow, text: CharSequence, tabSize: Int): Int? {
        val host = window.delegate
        var smallest: Int? = null

        for (line in 0 until window.lineCount) {
            val start = window.injectedToHost(window.getLineStartOffset(line))
            val end = window.injectedToHost(window.getLineEndOffset(line))

            if (start != host.getLineStartOffset(host.getLineNumber(start))) continue

            var i = start
            var width = 0
            while (i < end && (text[i] == ' ' || text[i] == '\t')) {
                width += if (text[i] == '\t') tabSize else 1
                i++
            }

            if (i < end) {
                smallest = minOf(smallest ?: width, width)
            }
        }

        return smallest
    }

    /** How many `{` in $text before $offset have no `}` after them, outside strings and comments. */
    private fun openBraces(text: String, offset: Int): Int {
        val lexer = WarrantLexer()
        lexer.start(text, 0, offset)
        var depth = 0

        while (lexer.tokenType != null) {
            when (lexer.tokenType) {
                WarrantTokenTypes.LBRACE -> depth++
                WarrantTokenTypes.RBRACE -> depth = maxOf(0, depth - 1)
            }
            lexer.advance()
        }

        return depth
    }
}
