<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * A cross-schema condition check:
 * `check(<predicate> for <handle> [with <map>])`.
 *
 * The predicate is a boolean expression whose leaves are the target schema's
 * conditions. It stops at `for`, which is neither an operator nor the start of
 * an operand.
 *
 * @extends GrammarParser<CrossSchemaConditionNode>
 */
final class ParseCrossSchemaCondition extends GrammarParser
{
    protected function read(): CrossSchemaConditionNode|NoMatch
    {
        if (! $this->check(TokenType::CHECK)) {
            return self::NOTHING;
        }

        $this->advance();
        $this->expect(TokenType::LPAREN, "Expected '(' after 'check'.");

        $predicate = ($this->parse(ParseBooleanExpression::class) ?? throw $this->missingBooleanExpressionError())->value;

        $this->expect(TokenType::FOR, "Expected 'for' after the condition predicate in 'check(...)'.");

        [$schemaKey, $isRowBound, $boundKey, $alias] = $this->parse(new ParseSchemaHandle(CrossSchemaConditionNode::class))->value;
        $contextMap = $this->parse(new ParseWithContextMap(CrossSchemaConditionNode::class))?->value ?? [];

        $this->expect(TokenType::RPAREN, "Expected ')' to close 'check(...)'.");

        return new CrossSchemaConditionNode($schemaKey, $predicate, $isRowBound, $boundKey, $contextMap, $alias);
    }
}
