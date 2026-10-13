<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One `they can ...` or `they cannot ...` clause. The clause node is recorded
 * from its `can` / `cannot`, without the `they`.
 *
 * A `they` with neither after it is a clause that says nothing: a tolerant parse
 * reports it and reads no clause there, so the rule keeps its other clauses.
 *
 * @extends Parser<CanClauseNode|CannotClauseNode|null>
 */
final class ParseTheyClause extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::THEY);
    }

    protected function read(): CanClauseNode|CannotClauseNode|null
    {
        $this->advance(); // consume 'they'

        $clause = $this->parse(new ParseCanClause) ?? $this->parse(new ParseCannotClause);

        if ($clause === null) {
            $this->report($this->errorAtCurrent(DiagnosticCode::MissingClause, "Expected 'can' or 'cannot' after 'they'."));

            return null;
        }

        return $clause->value;
    }
}
