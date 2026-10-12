<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The `cannot <abilities> [because <message>]` of a `they cannot` clause, from
 * the `cannot` on.
 *
 * @extends Parser<CannotClauseNode>
 */
final class ParseCannotClause extends Parser
{
    protected function read(): CannotClauseNode|CouldNotParse
    {
        if (! $this->check(TokenType::CANNOT)) {
            return self::COULD_NOT_PARSE;
        }

        $this->advance();
        $abilities = $this->parse(new ParseClauseAbilityList(CannotClauseNode::PART_ABILITIES))->value;
        $message = $this->parse(new ParseDenialMessage(CannotClauseNode::PART_MESSAGE), record: false)?->value;

        return new CannotClauseNode($abilities, $message);
    }
}
