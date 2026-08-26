package com.warrant.phpstorm

import com.intellij.lexer.LexerBase
import com.intellij.psi.TokenType
import com.intellij.psi.tree.IElementType

/**
 * Hand-written lexer for the Warrant DSL. It mirrors the token rules of
 * src/RuleSyntaxTree/Parsing/Lexer.php, with three differences required of an
 * editor lexer (see the plugin notes):
 *
 *   1. It never throws. Unrecognised input becomes [TokenType.BAD_CHARACTER].
 *   2. It accounts for every character — whitespace and `#` comments are emitted
 *      as tokens, not skipped.
 *   3. It is stateless (getState == 0 always), so the platform can restart it at
 *      any token boundary for incremental re-lexing.
 *
 * If you later prefer a generated lexer, this can be swapped for a JFlex
 * FlexAdapter without touching anything else.
 */
class WarrantLexer : LexerBase() {

    private var buffer: CharSequence = ""
    private var endOffset: Int = 0
    private var tokenStart: Int = 0
    private var tokenEnd: Int = 0
    private var tokenType: IElementType? = null

    override fun start(buffer: CharSequence, startOffset: Int, endOffset: Int, initialState: Int) {
        this.buffer = buffer
        this.endOffset = endOffset
        this.tokenStart = startOffset
        this.tokenEnd = startOffset
        locateToken()
    }

    override fun getState(): Int = 0
    override fun getTokenType(): IElementType? = tokenType
    override fun getTokenStart(): Int = tokenStart
    override fun getTokenEnd(): Int = tokenEnd
    override fun getBufferSequence(): CharSequence = buffer
    override fun getBufferEnd(): Int = endOffset

    override fun advance() {
        tokenStart = tokenEnd
        locateToken()
    }

    /** Scan a single token starting at [tokenStart]; set [tokenEnd] and [tokenType]. */
    private fun locateToken() {
        val pos = tokenStart
        if (pos >= endOffset) {
            tokenType = null
            tokenEnd = pos
            return
        }

        val c = buffer[pos]
        when {
            c.isWhitespace() -> {
                tokenEnd = consumeWhile(pos) { it.isWhitespace() }
                tokenType = TokenType.WHITE_SPACE
            }
            c == '#' -> {
                tokenEnd = consumeWhile(pos) { it != '\n' }
                tokenType = WarrantTokenTypes.COMMENT
            }
            c == '\'' || c == '"' -> scanString(pos)
            c == '@' -> {
                // @context / @column / @sql (tolerant: any @word). Lexer.php requires
                // exactly "context", "column", or "sql"; for colouring we accept the
                // @word and pick the token by which keyword it is (default: context).
                tokenEnd = consumeWhile(pos + 1) { isIdentPart(it) }
                tokenType = when (buffer.subSequence(pos, tokenEnd).toString()) {
                    "@column" -> WarrantTokenTypes.COLUMN_REF
                    "@sql" -> WarrantTokenTypes.SQL_REF
                    else -> WarrantTokenTypes.CONTEXT_REF
                }
            }
            c == ':' -> {
                if (pos + 1 < endOffset && isIdentStart(buffer[pos + 1])) {
                    tokenEnd = consumeWhile(pos + 1) { isIdentPart(it) }
                    tokenType = WarrantTokenTypes.NAMED_BINDING
                } else {
                    tokenEnd = pos + 1
                    tokenType = TokenType.BAD_CHARACTER
                }
            }
            isDigit(c) -> scanNumber(pos)
            c == '-' && pos + 1 < endOffset && isDigit(buffer[pos + 1]) -> scanNumber(pos)
            isIdentStart(c) -> scanWord(pos)
            c == '(' || c == ')' -> single(pos, WarrantTokenTypes.PARENS)
            c == '{' || c == '}' -> single(pos, WarrantTokenTypes.BRACES)
            c == ',' -> single(pos, WarrantTokenTypes.COMMA)
            c == '.' -> single(pos, WarrantTokenTypes.DOT)
            c == '!' || c == '*' || c == '?' || c == '=' -> single(pos, WarrantTokenTypes.OPERATOR)
            else -> single(pos, TokenType.BAD_CHARACTER)
        }
    }

    private fun single(pos: Int, type: IElementType) {
        tokenEnd = pos + 1
        tokenType = type
    }

    private fun scanWord(pos: Int) {
        val end = consumeWhile(pos) { isIdentPart(it) }
        val text = buffer.subSequence(pos, end).toString()
        tokenEnd = end
        tokenType = when {
            KEYWORDS.contains(text) -> WarrantTokenTypes.KEYWORD
            CONSTANTS.contains(text) -> WarrantTokenTypes.CONSTANT
            else -> WarrantTokenTypes.IDENTIFIER
        }
    }

    private fun scanNumber(pos: Int) {
        var i = pos
        if (buffer[i] == '-') i++
        while (i < endOffset && isDigit(buffer[i])) i++
        if (i + 1 < endOffset && buffer[i] == '.' && isDigit(buffer[i + 1])) {
            i++ // consume '.'
            while (i < endOffset && isDigit(buffer[i])) i++
        }
        tokenEnd = i
        tokenType = WarrantTokenTypes.NUMBER
    }

    private fun scanString(pos: Int) {
        val quote = buffer[pos]
        var i = pos + 1 // skip opening quote
        while (i < endOffset) {
            val c = buffer[i]
            if (c == '\\') {
                i += 2 // skip escaped char (\' or \" or \\)
                continue
            }
            if (c == quote) {
                i++ // consume closing quote
                break
            }
            i++
        }
        tokenEnd = minOf(i, endOffset)
        tokenType = WarrantTokenTypes.STRING
    }

    private inline fun consumeWhile(start: Int, pred: (Char) -> Boolean): Int {
        var i = start
        while (i < endOffset && pred(buffer[i])) i++
        return i
    }

    private fun isDigit(c: Char): Boolean = c in '0'..'9'

    private fun isIdentStart(c: Char): Boolean =
        c in 'a'..'z' || c in 'A'..'Z' || c == '_'

    // Matches Lexer::isIdentifierPart — letters, digits, underscore and dashes.
    private fun isIdentPart(c: Char): Boolean =
        isIdentStart(c) || isDigit(c) || c == '-'

    companion object {
        // Mirror of Lexer::KEYWORDS. Keep in sync with the PHP lexer.
        private val KEYWORDS = setOf(
            "if", "they", "can", "cannot", "because",
            "check", "and", "or", "not", "for", "with",
        )
        private val CONSTANTS = setOf("true", "false", "null")
    }
}
