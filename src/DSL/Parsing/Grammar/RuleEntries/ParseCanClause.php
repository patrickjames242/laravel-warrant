<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * The `can <abilities>` of a `they can` clause, from the `can` on.
 *
 * @extends GrammarParser<CanClauseNode>
 */
final class ParseCanClause extends GrammarParser
{
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): CanClauseNode|NoMatch
    {
        if (! $this->check(TokenType::CAN)) {
            return self::NOTHING;
        }

        $this->advance();

        return new CanClauseNode(
            $this->parse(new ParseClauseAbilityList($this->naming, CanClauseNode::PART_ABILITIES))->value,
        );
    }
}
