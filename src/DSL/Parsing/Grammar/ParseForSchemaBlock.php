<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\RuleEntries\LooksAheadAtRuleEntries;
use Warrant\DSL\Parsing\Grammar\RuleEntries\ParseRuleEntries;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A `for <schema>` header and the body it scopes, braced as
 * `for <schema> { <body> }` or unbraced and running to the end of the input.
 *
 * A braced block written as a bare `{`, with no header, is reported; a tolerant
 * parse reads it as though its header named no schema.
 *
 * The body is a rule set when a rule starts it or it is empty, and otherwise
 * one boolean expression scoped to the header's schema. The header is part of
 * the node, so the body is read here rather than by a parser of its own, which
 * would record the node from where the body starts.
 *
 * @extends Parser<ISchemaScopedNode>
 */
final class ParseForSchemaBlock extends Parser
{
    use ReportsMissingSyntax;
    use LooksAheadAtRuleEntries;

    public function __construct(private readonly bool $braced)
    {
    }

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::FOR) || $ahead->nextIs(TokenType::LBRACE);
    }

    protected function read(): ISchemaScopedNode
    {
        $schemaKey = INode::MISSING_NAME;

        if ($this->check(TokenType::LBRACE)) {
            $this->report($this->errorAtCurrent('A `{ ... }` block needs a `for <schema>` header before it.'));
        } else {
            $this->advance(); // consume 'for'

            if ($this->check(TokenType::IDENTIFIER)) {
                $schema = $this->advance();
                $this->part(ISchemaScopedNode::PART_SCHEMA_KEY, null, $schema);
                $schemaKey = $schema->lexeme;
            } else {
                $this->report($this->nameError('a schema name after `for`'));
            }
        }

        if (! $this->braced) {
            return $this->body($schemaKey, self::UNEXPECTED_AFTER_END);
        }

        $unclosed = "Expected '}' to close the rule set body.";

        $this->expectOrAssume(TokenType::LBRACE, "Expected '{' to open the rule set body.");
        $body = $this->body($schemaKey, $unclosed);
        $this->expectOrAssume(TokenType::RBRACE, $unclosed);

        return $body;
    }

    /**
     * @param string $schemaKey The schema the block's header names, which the
     *   body's node is scoped to.
     * @param string $unexpectedToken The error for a token after the body that
     *   should not be there, as {@see ParseRuleEntries} takes it.
     */
    private function body(string $schemaKey, string $unexpectedToken): ISchemaScopedNode
    {
        if ($this->check(TokenType::EOF) || $this->check(TokenType::RBRACE) || $this->ruleEntryAhead()) {
            return new RuleSetNode($schemaKey, $this->parse(new ParseRuleEntries($unexpectedToken))->value);
        }

        $this->assertNoAbilityListWithoutCanThey();

        return new SchemaConditionNode(
            $schemaKey,
            $this->expressionOrMissing(ParseBooleanExpression::class),
        );
    }
}
