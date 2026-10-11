<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * One operand of `and` / `or`: a negation, a parenthesized boolean expression,
 * a `can(...)`, a `check(...)` or a condition.
 *
 * @extends GrammarParser<IBooleanExpressionNode>
 */
final class ParseBooleanOperand extends GrammarParser
{
    protected function read(): IBooleanExpressionNode|NoMatch
    {
        // Each alternative starts with a different token, so the order decides
        // nothing but how many are tried; conditions are the most common, and go first.
        $operand = $this->parseOneOf([
            ParseCondition::class,
            ParseNegation::class,
            ParseParenthesizedBooleanExpression::class,
            ParseCrossSchemaCan::class,
            ParseCrossSchemaCondition::class,
        ]);

        return $operand === null ? self::NOTHING : $operand->value;
    }
}
