<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A rule with no `if`: a run of `they can` / `they cannot` clauses, which form
 * one unconditional rule.
 *
 * @extends Parser<RuleNode>
 */
final class ParseUnconditionalRule extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::THEY);
    }

    protected function read(): RuleNode
    {
        [$canClauses, $cannotClauses] = $this->parse(new ParseTheyCanAndCannotClauses)->value;

        return new RuleNode(null, $canClauses, $cannotClauses, $this->locationEndingHere());
    }
}
