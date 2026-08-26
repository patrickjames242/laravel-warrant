package com.warrant.phpstorm

import com.intellij.lexer.Lexer
import com.intellij.openapi.editor.DefaultLanguageHighlighterColors
import com.intellij.openapi.editor.HighlighterColors
import com.intellij.openapi.editor.colors.TextAttributesKey
import com.intellij.openapi.editor.colors.TextAttributesKey.createTextAttributesKey
import com.intellij.openapi.fileTypes.SyntaxHighlighterBase
import com.intellij.psi.TokenType
import com.intellij.psi.tree.IElementType

/**
 * Maps each lexer token to a [TextAttributesKey]. Each key inherits from a
 * built-in default, so the DSL automatically respects the user's colour scheme
 * (light/dark, custom themes) and can be overridden per-token in
 * Settings > Editor > Color Scheme.
 *
 * This is the IntelliJ analogue of the `"name": "keyword.control.warrant"` scopes
 * in editors/vscode/syntaxes/warrant.tmLanguage.json.
 */
class WarrantSyntaxHighlighter : SyntaxHighlighterBase() {

    override fun getHighlightingLexer(): Lexer = WarrantLexer()

    override fun getTokenHighlights(tokenType: IElementType): Array<TextAttributesKey> = pack(
        when (tokenType) {
            WarrantTokenTypes.KEYWORD -> KEYWORD
            WarrantTokenTypes.CONSTANT -> CONSTANT
            WarrantTokenTypes.IDENTIFIER -> IDENTIFIER
            WarrantTokenTypes.STRING -> STRING
            WarrantTokenTypes.NUMBER -> NUMBER
            WarrantTokenTypes.COMMENT -> COMMENT
            WarrantTokenTypes.CONTEXT_REF -> CONTEXT_REF
            WarrantTokenTypes.COLUMN_REF -> COLUMN_REF
            WarrantTokenTypes.NAMED_BINDING -> NAMED_BINDING
            WarrantTokenTypes.OPERATOR -> OPERATOR
            WarrantTokenTypes.PARENS -> PARENS
            WarrantTokenTypes.BRACES -> BRACES
            WarrantTokenTypes.COMMA -> COMMA
            WarrantTokenTypes.DOT -> DOT
            TokenType.BAD_CHARACTER -> BAD_CHARACTER
            else -> null
        }
    )

    companion object {
        val KEYWORD = createTextAttributesKey("WARRANT_KEYWORD", DefaultLanguageHighlighterColors.KEYWORD)
        val CONSTANT = createTextAttributesKey("WARRANT_CONSTANT", DefaultLanguageHighlighterColors.CONSTANT)
        // Ability / condition / schema names read like calls in this DSL.
        val IDENTIFIER = createTextAttributesKey("WARRANT_IDENTIFIER", DefaultLanguageHighlighterColors.FUNCTION_CALL)
        val STRING = createTextAttributesKey("WARRANT_STRING", DefaultLanguageHighlighterColors.STRING)
        val NUMBER = createTextAttributesKey("WARRANT_NUMBER", DefaultLanguageHighlighterColors.NUMBER)
        val COMMENT = createTextAttributesKey("WARRANT_COMMENT", DefaultLanguageHighlighterColors.LINE_COMMENT)
        val CONTEXT_REF = createTextAttributesKey("WARRANT_CONTEXT_REF", DefaultLanguageHighlighterColors.METADATA)
        val COLUMN_REF = createTextAttributesKey("WARRANT_COLUMN_REF", DefaultLanguageHighlighterColors.METADATA)
        val NAMED_BINDING = createTextAttributesKey("WARRANT_NAMED_BINDING", DefaultLanguageHighlighterColors.INSTANCE_FIELD)
        val OPERATOR = createTextAttributesKey("WARRANT_OPERATOR", DefaultLanguageHighlighterColors.OPERATION_SIGN)
        val PARENS = createTextAttributesKey("WARRANT_PARENS", DefaultLanguageHighlighterColors.PARENTHESES)
        val BRACES = createTextAttributesKey("WARRANT_BRACES", DefaultLanguageHighlighterColors.BRACES)
        val COMMA = createTextAttributesKey("WARRANT_COMMA", DefaultLanguageHighlighterColors.COMMA)
        val DOT = createTextAttributesKey("WARRANT_DOT", DefaultLanguageHighlighterColors.DOT)
        val BAD_CHARACTER = createTextAttributesKey("WARRANT_BAD_CHARACTER", HighlighterColors.BAD_CHARACTER)
    }
}
