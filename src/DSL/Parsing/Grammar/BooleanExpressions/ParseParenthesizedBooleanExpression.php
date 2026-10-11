<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A condition expression in parentheses.
 *
 * A group hands back the expression inside it, which keeps the span it was read
 * with: the parentheses belong to no node.
 *
 * @extends Parser<IBooleanExpressionNode>
 */
final class ParseParenthesizedBooleanExpression extends Parser
{
    use ReportsMissingSyntax;

    protected function read(): IBooleanExpressionNode|NoMatch
    {
        if (! $this->check(TokenType::LPAREN)) {
            return self::NOTHING;
        }

        $this->advance();
        $expression = ($this->parse(ParseBooleanExpression::class) ?? throw $this->missingBooleanExpressionError())->value;
        $this->expect(TokenType::RPAREN, "Expected ')' to close the group.");

        return $expression;
    }
}
