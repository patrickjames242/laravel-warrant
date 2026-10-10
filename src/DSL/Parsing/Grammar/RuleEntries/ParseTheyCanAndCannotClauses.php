<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

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
        $clauses = ($this->parseRepeated(new ParseTheyClause($this->naming))
            ?? throw $this->errorAtCurrent("Expected at least one 'they can ...' or 'they cannot ...' clause."))->value;

        return [
            array_values(array_filter($clauses, static fn ($clause) => $clause instanceof CanClauseNode)),
            array_values(array_filter($clauses, static fn ($clause) => $clause instanceof CannotClauseNode)),
        ];
    }
}
