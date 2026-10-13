<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ReadonlyParsingState;

/**
 * The `cannot <abilities> [because <message>]` of a `they cannot` clause, from
 * the `cannot` on.
 *
 * @extends Parser<CannotClauseNode>
 */
final class ParseCannotClause extends Parser
{
    protected static function startsHere(ReadonlyParsingState $state): bool
    {
        return $state->nextIs(TokenType::CANNOT);
    }

    protected function read(): CannotClauseNode
    {
        $this->advance(); // consume 'cannot'
        $abilities = $this->parse(new ParseClauseAbilityList(CannotClauseNode::PART_ABILITIES))->value;
        $message = $this->parse(new ParseDenialMessage(CannotClauseNode::PART_MESSAGE), record: false)?->value;

        return new CannotClauseNode($abilities, $message);
    }
}
