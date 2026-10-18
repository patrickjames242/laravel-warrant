<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\DiagnosticCode;
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
 * would place the node from where the body starts.
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
        if ($this->check(TokenType::LBRACE)) {
            $this->report($this->errorAtCurrent(DiagnosticCode::BlockWithoutHeader, 'A `{ ... }` block needs a `for <schema>` header before it.'));
            $schemaKey = new NameNode(INode::MISSING_NAME, $this->emptyLocationHere());
        } else {
            $this->advance(); // consume 'for'
            $schemaKey = $this->nameOrMissing('a schema name after `for`');
        }

        if (! $this->braced) {
            return $this->scoped($schemaKey, $this->body(self::UNEXPECTED_AFTER_END));
        }

        $unclosed = "Expected '}' to close the rule set body.";

        $this->expectOrAssume(TokenType::LBRACE, "Expected '{' to open the rule set body.");
        $body = $this->body($unclosed);
        $this->expectOrAssume(TokenType::RBRACE, $unclosed);

        return $this->scoped($schemaKey, $body);
    }

    /**
     * What the body holds: its rule entries, or its condition. It is read
     * before a braced block's `}`, and its node built after, so the node is
     * placed over the whole block.
     *
     * @param string $unexpectedToken The error for a token after the body that
     *   should not be there, as {@see ParseRuleEntries} takes it.
     * @return list<IRuleEntryNode>|IBooleanExpressionNode
     */
    private function body(string $unexpectedToken): array|IBooleanExpressionNode
    {
        if ($this->check(TokenType::EOF) || $this->check(TokenType::RBRACE) || $this->ruleEntryAhead()) {
            return $this->parse(new ParseRuleEntries($unexpectedToken))->value;
        }

        $this->assertNoAbilityListWithoutCanThey();

        return $this->expressionOrMissing(ParseBooleanExpression::class);
    }

    /**
     * The block's node: a rule set for rule entries, or a condition.
     *
     * @param NameNode $schemaKey The schema the block's header names, which the
     *   node is scoped to.
     * @param list<IRuleEntryNode>|IBooleanExpressionNode $body
     */
    private function scoped(NameNode $schemaKey, array|IBooleanExpressionNode $body): ISchemaScopedNode
    {
        if (is_array($body)) {
            return new RuleSetNode($schemaKey, $body, $this->locationEndingHere());
        }

        return new SchemaConditionNode($schemaKey, $body, $this->locationEndingHere());
    }
}
