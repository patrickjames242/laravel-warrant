<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * One `they can ...` or `they cannot ...` clause. The clause node is recorded
 * from its `can` / `cannot`, without the `they`.
 *
 * @extends Parser<CanClauseNode|CannotClauseNode>
 */
final class ParseTheyClause extends Parser
{
    protected function read(): CanClauseNode|CannotClauseNode|CouldNotParse
    {
        if (! $this->check(TokenType::THEY)) {
            return self::COULD_NOT_PARSE;
        }

        $this->advance();

        return ($this->parse(new ParseCanClause)
            ?? $this->parse(new ParseCannotClause)
            ?? throw $this->errorAtCurrent("Expected 'can' or 'cannot' after 'they'."))->value;
    }
}
