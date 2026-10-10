<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;

/**
 * Any number of `not` (or `!`) before a parenthesized boolean expression,
 * a `can(...)`, a `check(...)` or a condition.
 *
 * @extends GrammarParser<IBooleanExpressionNode>
 */
final class ParseNot extends GrammarParser
{
    protected function read(): IBooleanExpressionNode|NoMatch
    {
        if (! $this->check(TokenType::NOT)) {
            return $this->parse(new ParseOneOf([
                ParseParenthesizedBooleanExpression::class,
                ParseCrossSchemaCan::class,
                ParseCrossSchemaCondition::class,
                ParseCondition::class,
            ]))?->value ?? self::NOTHING;
        }

        $this->advance();

        return new NotNode(
            ($this->parse($this) ?? throw $this->missingBooleanExpressionError())->value,
        );
    }
}
