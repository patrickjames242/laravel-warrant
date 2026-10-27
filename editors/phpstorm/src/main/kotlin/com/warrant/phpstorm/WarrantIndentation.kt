package com.warrant.phpstorm

import com.intellij.injected.editor.DocumentWindow
import com.intellij.lang.injection.InjectedLanguageManager
import com.intellij.openapi.editor.Document
import com.intellij.openapi.project.Project
import com.intellij.psi.PsiDocumentManager
import com.intellij.psi.PsiFile

/**
 * Where a line of Warrant goes, by the rules the Warrant formatter lays text out
 * with, measured in the document the editor shows: a `.warrant` file, or the
 * PHP file the Warrant is written in.
 *
 * A line inside a `{ }` or `( )` goes one level past the line its innermost
 * unclosed bracket is on; a line that starts with the closing bracket goes back
 * to that line's indentation. A line after one that continues a condition with
 * `and` or `or` stays at that line's indentation. Anything else stands at the
 * Warrant's top level. A level is four spaces, as the formatter writes.
 *
 * In a `.warrant` file the top level is column 0. Inside PHP it is the
 * indentation of the least-indented line of the Warrant, which in a heredoc is
 * the heredoc's own; a line the Warrant does not start, such as the first line
 * of a string opened mid-line, does not count.
 *
 * @param host The document the editor shows.
 * @param text The Warrant text.
 * @param toHost Where an offset in [text] is in [host].
 * @param toWarrant Where an offset in [host] is in [text].
 * @param topLevel The indentation of the Warrant's top level.
 */
class WarrantIndentation private constructor(
    private val host: Document,
    private val text: CharSequence,
    private val toHost: (Int) -> Int,
    private val toWarrant: (Int) -> Int,
    private val topLevel: Int,
) {

    /**
     * The indentation for the line starting at [lineStart] in the host, by
     * what comes before it and, when it starts with a closing bracket, by that.
     */
    fun forLineAt(lineStart: Int): Int {
        val opener = innermostOpenerBefore(lineStart)
        val firstChar = firstNonBlank(lineStart)

        if (firstChar == '}' || firstChar == ')') {
            return opener?.let(::lineIndentAt) ?: topLevel
        }

        val line = host.getLineNumber(lineStart)
        if (line > 0) {
            val previousStart = host.getLineStartOffset(line - 1)
            val previous = host.charsSequence.subSequence(previousStart, host.getLineEndOffset(line - 1))
            val openerOnPrevious = opener != null && host.getLineNumber(opener) == line - 1

            if (!openerOnPrevious && CONTINUATION.containsMatchIn(previous)) {
                return lineIndentAt(previousStart)
            }
        }

        return opener?.let { lineIndentAt(it) + LEVEL } ?: topLevel
    }

    /**
     * Set the indentation of the line starting at [lineStart] by [forLineAt],
     * and answer where its text now starts.
     */
    fun indentLineAt(lineStart: Int): Int {
        val text = host.charsSequence
        var end = lineStart
        while (end < host.textLength && (text[end] == ' ' || text[end] == '\t')) end++

        val indent = " ".repeat(forLineAt(lineStart))
        host.replaceString(lineStart, end, indent)

        return lineStart + indent.length
    }

    /** The host offset of the innermost `{` or `(` before [hostOffset] still open there. */
    private fun innermostOpenerBefore(hostOffset: Int): Int? {
        val lexer = WarrantLexer()
        lexer.start(text, 0, toWarrant(hostOffset).coerceIn(0, text.length))
        val open = ArrayDeque<Int>()

        while (lexer.tokenType != null) {
            when (lexer.tokenType) {
                WarrantTokenTypes.LBRACE, WarrantTokenTypes.LPAREN -> open.addLast(lexer.tokenStart)
                WarrantTokenTypes.RBRACE, WarrantTokenTypes.RPAREN -> open.removeLastOrNull()
            }
            lexer.advance()
        }

        return open.lastOrNull()?.let(toHost)
    }

    /** The indentation of the host line holding [hostOffset]. */
    private fun lineIndentAt(hostOffset: Int): Int {
        val text = host.charsSequence
        var i = host.getLineStartOffset(host.getLineNumber(hostOffset))
        var width = 0
        while (i < host.textLength && (text[i] == ' ' || text[i] == '\t')) {
            width += if (text[i] == '\t') LEVEL else 1
            i++
        }
        return width
    }

    private fun firstNonBlank(lineStart: Int): Char? {
        val text = host.charsSequence
        val end = host.getLineEndOffset(host.getLineNumber(lineStart))
        return (lineStart until end).map { text[it] }.firstOrNull { it != ' ' && it != '\t' }
    }

    companion object {
        /** The spaces in one level, as the formatter writes. */
        const val LEVEL = 4

        /** A line that continues a condition. */
        private val CONTINUATION = Regex("""^\s*(and|or)\b""")

        /**
         * The indentation of the Warrant at [hostOffset] in [hostFile], or null
         * when no Warrant is there.
         */
        fun at(project: Project, hostFile: PsiFile, host: Document, hostOffset: Int): WarrantIndentation? {
            if (hostFile is WarrantFile) {
                return WarrantIndentation(host, host.charsSequence, { it }, { it }, 0)
            }

            val warrant = InjectedLanguageManager.getInstance(project).findInjectedElementAt(hostFile, hostOffset)?.containingFile as? WarrantFile
                ?: return null
            val window = PsiDocumentManager.getInstance(project).getDocument(warrant) as? DocumentWindow ?: return null
            val topLevel = topLevelOf(window, host.charsSequence) ?: return null

            return WarrantIndentation(host, warrant.text, window::injectedToHost, window::hostToInjected, topLevel)
        }

        /**
         * The width of the least-indented line of the Warrant in [window] that
         * holds more than whitespace, among the lines it starts, measured in the
         * host's [text]. Null when there is none.
         */
        private fun topLevelOf(window: DocumentWindow, text: CharSequence): Int? {
            val host = window.delegate
            var smallest: Int? = null

            for (line in 0 until window.lineCount) {
                val start = window.injectedToHost(window.getLineStartOffset(line))
                val end = window.injectedToHost(window.getLineEndOffset(line))

                if (start != host.getLineStartOffset(host.getLineNumber(start))) continue

                var i = start
                var width = 0
                while (i < end && (text[i] == ' ' || text[i] == '\t')) {
                    width += if (text[i] == '\t') LEVEL else 1
                    i++
                }

                if (i < end) {
                    smallest = minOf(smallest ?: width, width)
                }
            }

            return smallest
        }
    }
}
