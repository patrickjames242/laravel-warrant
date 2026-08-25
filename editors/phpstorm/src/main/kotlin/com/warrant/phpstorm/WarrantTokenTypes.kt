package com.warrant.phpstorm

import com.intellij.psi.tree.IElementType

/** A lexer token belonging to the Warrant language. */
class WarrantTokenType(debugName: String) : IElementType(debugName, WarrantLanguage)

/**
 * The token vocabulary the lexer emits. Kept intentionally coarse — grouped by
 * *color*, not by grammar role — because highlighting is all this needs today.
 * Whitespace and invalid input use the platform's own token types
 * (`TokenType.WHITE_SPACE` / `TokenType.BAD_CHARACTER`).
 *
 * Source of truth for the underlying rules: src/RuleSyntaxTree/Parsing/Lexer.php.
 */
object WarrantTokenTypes {
    @JvmField val KEYWORD = WarrantTokenType("WARRANT_KEYWORD")            // if they can cannot because check and or not for with
    @JvmField val CONSTANT = WarrantTokenType("WARRANT_CONSTANT")         // true false null
    @JvmField val IDENTIFIER = WarrantTokenType("WARRANT_IDENTIFIER")     // ability / condition / schema names
    @JvmField val STRING = WarrantTokenType("WARRANT_STRING")            // '...'
    @JvmField val NUMBER = WarrantTokenType("WARRANT_NUMBER")            // 42, -3.5
    @JvmField val COMMENT = WarrantTokenType("WARRANT_COMMENT")          // # ...
    @JvmField val CONTEXT_REF = WarrantTokenType("WARRANT_CONTEXT_REF")  // @context
    @JvmField val NAMED_BINDING = WarrantTokenType("WARRANT_NAMED_BINDING") // :name
    @JvmField val OPERATOR = WarrantTokenType("WARRANT_OPERATOR")        // ! * ? =
    @JvmField val PARENS = WarrantTokenType("WARRANT_PARENS")            // ( )
    @JvmField val BRACES = WarrantTokenType("WARRANT_BRACES")            // { }
    @JvmField val COMMA = WarrantTokenType("WARRANT_COMMA")              // ,
}
