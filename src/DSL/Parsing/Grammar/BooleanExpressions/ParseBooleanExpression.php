<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A boolean expression: operands joined by `and` and `or`, where `and` binds
 * tighter than `or` and both group from the left, so `a or b and c and d` is
 * `a or ((b and c) and d)`. What an operand can be is
 * {@see ParseBooleanOperand}'s to say.
 *
 * Each `and` / `or` node is recorded from its first operand to its last.
 *
 * @extends Parser<IBooleanExpressionNode>
 */
final class ParseBooleanExpression extends Parser
{
    use ReportsMissingSyntax;

    protected function read(): IBooleanExpressionNode|NoMatch
    {
        $start = $this->peek();
        $operand = $this->parse(ParseBooleanOperand::class);

        if ($operand === null) {
            return self::NOTHING;
        }

        return $this->joinOperators($operand->value, $start, 0);
    }

    /**
     * How tightly the binary operator $type binds, higher binding tighter; null
     * when $type is not one.
     */
    private static function precedence(TokenType $type): ?int
    {
        return match ($type) {
            TokenType::OR => 1,
            TokenType::AND => 2,
            default => null,
        };
    }

    private static function join(TokenType $operator, IBooleanExpressionNode $left, IBooleanExpressionNode $right): IBooleanExpressionNode
    {
        return match ($operator) {
            TokenType::OR => new OrNode($left, $right),
            TokenType::AND => new AndNode($left, $right),
        };
    }

    /**
     * Join $left, which starts at $start, to the operators after it that bind
     * at least as tightly as $minimum.
     */
    private function joinOperators(IBooleanExpressionNode $left, Token $start, int $minimum): IBooleanExpressionNode
    {
        while (($precedence = self::precedence($this->peek()->type)) !== null && $precedence >= $minimum) {
            $operator = $this->advance()->type;
            $rightStart = $this->peek();
            $right = ($this->parse(ParseBooleanOperand::class) ?? throw $this->missingBooleanExpressionError())->value;

            // An operator after the right operand that binds tighter takes it first.
            $right = $this->joinOperators($right, $rightStart, $precedence + 1);

            $left = $this->node(self::join($operator, $left, $right), $start);
        }

        return $left;
    }
}
