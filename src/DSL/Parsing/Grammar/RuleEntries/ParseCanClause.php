<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The `can <abilities>` of a `they can` clause, from the `can` on.
 *
 * @extends Parser<CanClauseNode>
 */
final class ParseCanClause extends Parser
{
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): CanClauseNode|CouldNotParse
    {
        if (! $this->check(TokenType::CAN)) {
            return self::COULD_NOT_PARSE;
        }

        $this->advance();

        return new CanClauseNode(
            $this->parse(new ParseClauseAbilityList($this->naming, CanClauseNode::PART_ABILITIES))->value,
        );
    }
}
