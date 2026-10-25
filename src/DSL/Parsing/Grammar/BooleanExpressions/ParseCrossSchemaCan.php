<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseBinding;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * An ability check: `can(<ability>)`, or
 * `can(<ability> for <handle> [with <map>])`. A reference that is only an
 * ability may leave its parentheses off, as `can <ability>`; one with a `for`
 * or a `with` keeps them, so that in `check(can view for folders)` the `for`
 * belongs to the `check`. The ability is a name, or a binding whose value
 * names it.
 *
 * In expression position `can` is always this builtin: the can/cannot clause keyword in
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

        if (! $this->check(TokenType::LPAREN)) {
            $ability = $this->abilityOrMissing('an ability name or \'(\' after \'can\'');

            return new CrossSchemaCanNode(null, $ability, false, [], [], null, $this->locationEndingHere());
        }

        $this->advance(); // consume '('

        $ability = $this->abilityOrMissing('an ability name');

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

    /**
     * The ability the reference asks about: a binding, or else a name, reported
     * as missing when none is written.
     */
    private function abilityOrMissing(string $expected): NameNode|BindingNode
    {
        if ($this->check(TokenType::NAMED_BINDING)) {
            return $this->parse(ParseBinding::class)->value;
        }

        return $this->nameOrMissing($expected);
    }
}
