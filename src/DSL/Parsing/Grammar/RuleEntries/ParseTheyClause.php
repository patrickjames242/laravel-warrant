<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * One `they can ...` or `they cannot ...` clause. The clause node is recorded
 * from its `can` / `cannot`, without the `they`.
 *
 * @extends GrammarParser<CanClauseNode|CannotClauseNode>
 */
final class ParseTheyClause extends GrammarParser
{
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): CanClauseNode|CannotClauseNode|NoMatch
    {
        if (! $this->check(TokenType::THEY)) {
            return self::NOTHING;
        }

        $this->advance();

        if ($can = $this->parse(new ParseCanClause($this->naming))) {
            // A `because` message only ever surfaces for a matching `cannot`;
            // hanging one off a `can` clause can never fire, so reject it here.
            if ($this->check(TokenType::BECAUSE)) {
                throw $this->errorAtCurrent(
                    "'because' may only follow a 'they cannot ...' clause, not 'they can ...'."
                );
            }

            return $can->value;
        }

        return ($this->parse(new ParseCannotClause($this->naming))
            ?? throw $this->errorAtCurrent("Expected 'can' or 'cannot' after 'they'."))->value;
    }
}
