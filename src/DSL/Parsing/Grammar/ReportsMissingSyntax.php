<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * The errors for a spot where something must be written and is not: a name,
 * a boolean expression, an argument. Used by parsers of the grammar, which
 * supply the token helpers these read with.
 */
trait ReportsMissingSyntax
{
    /**
     * The keywords that are spelled as words, and so could be mistaken for a
     * name.
     */
    private const RESERVED_WORDS = [
        TokenType::IF,
        TokenType::THEY,
        TokenType::CAN,
        TokenType::CANNOT,
        TokenType::BECAUSE,
        TokenType::CHECK,
        TokenType::AND,
        TokenType::OR,
        TokenType::NOT,
        TokenType::FOR,
        TokenType::WITH,
        TokenType::AS,
    ];

    /**
     * Error for a spot expecting a name, with a clearer hint when the offending
     * token is a reserved word (which cannot be used as a name).
     */
    private function nameError(string $expected): WarrantSyntaxException
    {
        $token = $this->peek();

        // `!` is a NOT too; only a keyword spelled as a word looks like a name.
        if (in_array($token->type, self::RESERVED_WORDS, true) && ctype_alpha($token->lexeme)) {
            return $this->errorAtCurrent(
                sprintf("Reserved word '%s' cannot be used as a name; expected %s.", $token->lexeme, $expected)
            );
        }

        return $this->errorAtCurrent(sprintf('Expected %s.', $expected));
    }

    /**
     * Error for a spot where a boolean expression must start and none does.
     */
    private function missingBooleanExpressionError(): WarrantSyntaxException
    {
        return $this->nameError("a condition, 'can(', 'check(', or '('");
    }

    /**
     * Error for a spot where an argument must be and none is.
     */
    private function missingArgumentError(): WarrantSyntaxException
    {
        return $this->errorAtCurrent(
            'Expected an argument: a literal, a binding (:name or ?), @context <key>, '
                .'@column <column> or @column <name>.<column>, or @sql "<sql>".'
        );
    }
}
