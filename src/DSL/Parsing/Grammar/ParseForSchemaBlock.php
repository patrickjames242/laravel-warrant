<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\RuleEntries\AbilityNaming;
use Warrant\DSL\Parsing\Grammar\RuleEntries\LooksAheadAtRuleEntries;
use Warrant\DSL\Parsing\Grammar\RuleEntries\ParseRuleEntries;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A `for <schema>` header and the body it scopes, braced as
 * `for <schema> { <body> }` or unbraced and running to the end of the input.
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

    protected function read(): ISchemaScopedNode|NoMatch
    {
        if (! $this->check(TokenType::FOR)) {
            return self::NOTHING;
        }

        $this->advance();

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('a schema name after `for`');
        }

        $schema = $this->advance();
        $this->part(ISchemaScopedNode::PART_SCHEMA_KEY, null, $schema);

        if (! $this->braced) {
            return $this->body($schema->lexeme);
        }

        $this->expect(TokenType::LBRACE, "Expected '{' to open the rule set body.");
        $body = $this->body($schema->lexeme);
        $this->expect(TokenType::RBRACE, "Expected '}' to close the rule set body.");

        return $body;
    }

    private function body(string $schemaKey): ISchemaScopedNode
    {
        $atEnd = $this->check(TokenType::EOF) || $this->check(TokenType::RBRACE);
        $entries = $this->parse(new ParseRuleEntries(AbilityNaming::Required))->value;

        if ($atEnd || $entries !== []) {
            return new RuleSetNode($schemaKey, $entries);
        }

        $this->assertNoAbilityListWithoutCanThey();

        return new SchemaConditionNode(
            $schemaKey,
            ($this->parse(ParseBooleanExpression::class) ?? throw $this->missingBooleanExpressionError())->value,
        );
    }
}
