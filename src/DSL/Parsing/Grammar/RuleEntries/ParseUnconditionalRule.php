<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A rule with no `if`: a run of `they can` / `they cannot` clauses, which form
 * one unconditional rule.
 *
 * @extends Parser<WarrantRuleNode>
 */
final class ParseUnconditionalRule extends Parser
{
    protected static function startsWith(): TokenType
    {
        return TokenType::THEY;
    }

    protected function read(): WarrantRuleNode
    {
        [$canClauses, $cannotClauses] = $this->parse(new ParseTheyCanAndCannotClauses)->value;

        return new WarrantRuleNode(null, $canClauses, $cannotClauses);
    }
}
