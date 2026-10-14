package com.warrant.phpstorm

import com.intellij.codeInsight.editorActions.SimpleTokenSetQuoteHandler
import com.intellij.openapi.editor.highlighter.HighlighterIterator

/**
 * Closes a Warrant string literal, `'...'` or `"..."`, as its opening quote is
 * typed, and steps over the closing quote when it is typed again. Inside a
 * string or a comment a quote is typed as it is.
 *
 * This decides only what typing a quote does, not what the text means: the
 * lexer reads strings as Lexer.php does. A string may run across lines, so a
 * quote just typed is read as the start of a string that ends at the next
 * matching quote, which is usually the opening quote of a later message. For
 * the decision to close it, a string that crosses a line break counts as
 * unclosed, so the quote typed before it is closed on its own line.
 */
class WarrantQuoteHandler : SimpleTokenSetQuoteHandler(WarrantTokenTypes.STRING) {

    override fun isNonClosedLiteral(iterator: HighlighterIterator, chars: CharSequence): Boolean =
        super.isNonClosedLiteral(iterator, chars) || (iterator.start until iterator.end).any { chars[it] == '\n' }
}
