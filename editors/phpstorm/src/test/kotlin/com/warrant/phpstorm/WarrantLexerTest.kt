package com.warrant.phpstorm

import com.intellij.testFramework.fixtures.BasePlatformTestCase

/**
 * The plugin's lexer reads string literals as src/DSL/Lexing/Lexer.php does. The
 * expected spans are the ones Lexer.php gives for the same text.
 */
class WarrantLexerTest : BasePlatformTestCase() {

    fun testKeepsAClosedStringWholeAcrossLines() {
        assertEquals(listOf(8 to 13), strings("because 'a\nb' x"))
    }

    fun testEndsAnUnclosedStringAtItsFirstLineBreak() {
        assertEquals(listOf(8 to 10), strings("because 'a\nb x"))
    }

    fun testEndsAnUnclosedStringAfterTheBackslashOfAnEscapedLineBreak() {
        assertEquals(listOf(8 to 11), strings("because 'a\\\nb x"))
    }

    fun testClosesAStringAtTheNextMatchingQuoteOnALaterLine() {
        assertEquals(listOf(8 to 16, 17 to 18), strings("because 'a\nb\nc 'd'"))
    }

    fun testRunsAnUnclosedStringOnOneLineToTheEnd() {
        assertEquals(listOf(8 to 21), strings("because 'never closed"))
    }

    fun testKeepsAnEscapedQuoteInsideTheString() {
        assertEquals(listOf(8 to 14), strings("because \"a\\\"b\" x"))
    }

    /** The start and end offsets of each string literal the lexer finds in $text. */
    private fun strings(text: String): List<Pair<Int, Int>> {
        val lexer = WarrantLexer()
        lexer.start(text)
        val spans = mutableListOf<Pair<Int, Int>>()

        while (lexer.tokenType != null) {
            if (lexer.tokenType == WarrantTokenTypes.STRING) spans.add(lexer.tokenStart to lexer.tokenEnd)
            lexer.advance()
        }

        return spans
    }
}
