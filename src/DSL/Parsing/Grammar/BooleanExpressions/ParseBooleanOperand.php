<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Parsing\ASTNodes\BooleanExpressionNode;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;

/**
 * One operand of `and` / `or`: a negation, a parenthesized boolean expression,
 * a `can(...)`, a `check(...)` or a condition.
 *
 * @extends ParseOneOf<BooleanExpressionNode>
 */
final class ParseBooleanOperand extends ParseOneOf
{
    protected static function parsers(): array
    {
        // Each alternative starts with a different token, so the order decides
        // nothing but how many are tried; conditions are the most common, and go first.
        return [
            ParseCondition::class,
            ParseNegation::class,
            ParseParenthesizedBooleanExpression::class,
            ParseCrossSchemaCan::class,
            ParseCrossSchemaCondition::class,
        ];
    }
}
