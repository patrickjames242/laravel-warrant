<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * The `can <abilities>` of a `they can` clause, from the `can` on.
 *
 * @extends Parser<CanClauseNode>
 */
final class ParseCanClause extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::CAN);
    }

    protected function read(): CanClauseNode
    {
        $this->advance(); // consume 'can'

        return new CanClauseNode(
            $this->parse(new ParseClauseAbilityList(CanClauseNode::PART_ABILITIES))->value,
            $this->parse(new ParseDenialMessage(CanClauseNode::PART_MESSAGE))?->value,
            $this->located(),
        );
    }
}
