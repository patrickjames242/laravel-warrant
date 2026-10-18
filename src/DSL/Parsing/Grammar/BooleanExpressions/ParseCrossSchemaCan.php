<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

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

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::CAN);
    }

    protected function read(): CrossSchemaCanNode
    {
        $this->advance(); // consume 'can'
        $this->expectOrAssume(TokenType::LPAREN, "Expected '(' after 'can'.");

        $ability = $this->nameOrMissing('an ability name');

        /* No `for`, no boundary: the reference stays on this schema and this row,
           so there is no handle to read. A `with` map is still read, so that
           handing context to a reference that crosses nothing is answered by
           validation, where the reason can be given, rather than by a parse error
           about a missing keyword. */
        if (! $this->check(TokenType::FOR)) {
            $contextMap = $this->parse(ParseWithContextMap::class)?->value ?? [];
            $this->expectOrAssume(TokenType::RPAREN, "Expected 'for' or ')' after the ability name in 'can(...)'.");

            return new CrossSchemaCanNode(null, $ability, false, [], $contextMap, null, $this->locationEndingHere());
        }

        $this->advance(); // consume 'for'

        [$schemaKey, $isRowBound, $boundKey, $alias] = $this->parse(ParseSchemaHandle::class)->value;
        $contextMap = $this->parse(ParseWithContextMap::class)?->value ?? [];

        $this->expectOrAssume(TokenType::RPAREN, "Expected ')' to close 'can(...)'.");

        return new CrossSchemaCanNode($schemaKey, $ability, $isRowBound, $boundKey, $contextMap, $alias, $this->locationEndingHere());
    }
}
