<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;

/**
 * The run of `they can ...` / `they cannot ...` clauses that one rule holds:
 * all the clauses after an `if`, or the leading clauses with none. There is at
 * least one. Each clause is its own node, so distinct `cannot` clauses keep
 * distinct messages on the same rule.
 *
 * @extends GrammarParser<array{0: list<CanClauseNode>, 1: list<CannotClauseNode>}>
 */
final class ParseTheyCanAndCannotClauses extends GrammarParser
{
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    /**
     * @return array{0: list<CanClauseNode>, 1: list<CannotClauseNode>}
     */
    protected function read(): array
    {
        if (! $this->check(TokenType::THEY)) {
            throw $this->errorAtCurrent("Expected at least one 'they can ...' or 'they cannot ...' clause.");
        }

        $canClauses = [];
        $cannotClauses = [];

        while ($this->check(TokenType::THEY)) {
            $this->advance();

            if ($can = $this->parse(new ParseCanClause($this->naming))) {
                $canClauses[] = $can->value;

                // A `because` message only ever surfaces for a matching `cannot`;
                // hanging one off a `can` clause can never fire, so reject it here.
                if ($this->check(TokenType::BECAUSE)) {
                    throw $this->errorAtCurrent(
                        "'because' may only follow a 'they cannot ...' clause, not 'they can ...'."
                    );
                }
            } elseif ($cannot = $this->parse(new ParseCannotClause($this->naming))) {
                $cannotClauses[] = $cannot->value;
            } else {
                throw $this->errorAtCurrent("Expected 'can' or 'cannot' after 'they'.");
            }
        }

        return [$canClauses, $cannotClauses];
    }
}
