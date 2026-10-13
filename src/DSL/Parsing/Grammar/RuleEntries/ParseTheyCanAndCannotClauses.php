<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The run of `they can ...` / `they cannot ...` clauses that one rule holds:
 * all the clauses after an `if`, or the leading clauses with none. There is at
 * least one. Each clause is its own node, so distinct `cannot` clauses keep
 * distinct messages on the same rule.
 *
 * A tolerant parse reports a rule with none and reads it with no clauses, so
 * the rest of the rule, its condition, is kept. A `they` that heads no clause
 * adds none.
 *
 * @extends Parser<array{0: list<CanClauseNode>, 1: list<CannotClauseNode>}>
 */
final class ParseTheyCanAndCannotClauses extends Parser
{
    /**
     * @return array{0: list<CanClauseNode>, 1: list<CannotClauseNode>}
     */
    protected function read(): array
    {
        $clauses = $this->parseRepeated(new ParseTheyClause);

        if ($clauses === []) {
            $this->report($this->errorAtCurrent("Expected at least one 'they can ...' or 'they cannot ...' clause."));
        }

        return [
            array_values(array_filter($clauses, static fn ($clause) => $clause instanceof CanClauseNode)),
            array_values(array_filter($clauses, static fn ($clause) => $clause instanceof CannotClauseNode)),
        ];
    }
}
