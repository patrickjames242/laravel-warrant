<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A cross-schema condition check:
 * `check(<predicate> for <handle> [with <map>])`.
 *
 * The predicate is a boolean expression whose leaves are the target schema's
 * conditions. It stops at `for`, which is neither an operator nor the start of
 * an operand.
 *
 * @extends Parser<CrossSchemaConditionNode>
 */
final class ParseCrossSchemaCondition extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::CHECK);
    }

    protected function read(): CrossSchemaConditionNode
    {
        $this->advance(); // consume 'check'
        $this->expectOrAssume(TokenType::LPAREN, "Expected '(' after 'check'.");

        $predicate = $this->expressionOrMissing(ParseBooleanExpression::class);

        $this->expectOrAssume(TokenType::FOR, "Expected 'for' after the condition predicate in 'check(...)'.");

        [$schemaKey, $isRowBound, $boundKey, $alias] = $this->parse(new ParseSchemaHandle(CrossSchemaConditionNode::class))->value;
        $contextMap = $this->parse(new ParseWithContextMap(CrossSchemaConditionNode::class))?->value ?? [];

        $this->expectOrAssume(TokenType::RPAREN, "Expected ')' to close 'check(...)'.");

        return new CrossSchemaConditionNode($schemaKey, $predicate, $isRowBound, $boundKey, $contextMap, $alias);
    }
}
