<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A rule with no `if`: a run of `they can` / `they cannot` clauses, which form
 * one unconditional rule.
 *
 * @extends Parser<WarrantRuleNode>
 */
final class ParseUnconditionalRule extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::THEY);
    }

    protected function read(): WarrantRuleNode
    {
        [$canClauses, $cannotClauses] = $this->parse(new ParseTheyCanAndCannotClauses)->value;

        return new WarrantRuleNode(null, $canClauses, $cannotClauses, $this->located());
    }
}
