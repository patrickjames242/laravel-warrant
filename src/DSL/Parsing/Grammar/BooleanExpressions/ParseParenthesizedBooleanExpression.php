<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ReadonlyParsingState;

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

    protected static function startsHere(ReadonlyParsingState $state): bool
    {
        return $state->nextIs(TokenType::LPAREN);
    }

    protected function read(): IBooleanExpressionNode
    {
        $this->advance(); // consume '('
        $expression = ($this->parse(ParseBooleanExpression::class) ?? throw $this->missingBooleanExpressionError())->value;
        $this->expect(TokenType::RPAREN, "Expected ')' to close the group.");

        return $expression;
    }
}
