<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * An ability check: `can(<ability>)`, or
 * `can(<ability> for <handle> [with <map>])`.
 *
 * In expression position `can` is always this builtin: the clause keyword in
 * `they can ...` is read by {@see \Warrant\DSL\Parsing\Grammar\RuleEntries\ParseCanClause} and never reaches here.
 *
 * @extends Parser<CrossSchemaCanNode>
 */
final class ParseCrossSchemaCan extends Parser
{
    use ReportsMissingSyntax;

    protected function read(): CrossSchemaCanNode|NoMatch
    {
        if (! $this->check(TokenType::CAN)) {
            return self::NOTHING;
        }

        $this->advance();
        $this->expect(TokenType::LPAREN, "Expected '(' after 'can'.");

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('an ability name');
        }

        $ability = $this->advance();
        $this->part(CrossSchemaCanNode::PART_ABILITY, null, $ability);

        /* No `for`, no boundary: the reference stays on this schema and this row,
           so there is no handle to read. A `with` map is still read, so that
           handing context to a reference that crosses nothing is answered by
           validation, where the reason can be given, rather than by a parse error
           about a missing keyword. */
        if (! $this->check(TokenType::FOR)) {
            $contextMap = $this->parse(new ParseWithContextMap(CrossSchemaCanNode::class))?->value ?? [];
            $this->expect(TokenType::RPAREN, "Expected 'for' or ')' after the ability name in 'can(...)'.");

            return new CrossSchemaCanNode(null, $ability->lexeme, contextMap: $contextMap);
        }

        $this->advance(); // consume 'for'

        [$schemaKey, $isRowBound, $boundKey, $alias] = $this->parse(new ParseSchemaHandle(CrossSchemaCanNode::class))->value;
        $contextMap = $this->parse(new ParseWithContextMap(CrossSchemaCanNode::class))?->value ?? [];

        $this->expect(TokenType::RPAREN, "Expected ')' to close 'can(...)'.");

        return new CrossSchemaCanNode($schemaKey, $ability->lexeme, $isRowBound, $boundKey, $contextMap, $alias);
    }
}
