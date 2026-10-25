<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * The `cannot <abilities> [because <message>]` of a `they cannot` clause, from
 * the `cannot` on.
 *
 * @extends Parser<CannotClauseNode>
 */
final class ParseCannotClause extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::CANNOT);
    }

    protected function read(): CannotClauseNode
    {
        $this->advance(); // consume 'cannot'
        $abilities = $this->parse(ParseCanOrCannotAbilityList::class)->value;
        $message = $this->parse(ParseDenialMessage::class)?->value;

        return new CannotClauseNode($abilities, $message, $this->locationEndingHere());
    }
}
