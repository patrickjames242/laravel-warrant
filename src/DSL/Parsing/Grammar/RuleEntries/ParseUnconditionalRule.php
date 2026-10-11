<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A rule with no `if`: a run of `they can` / `they cannot` clauses, which form
 * one unconditional rule.
 *
 * @extends Parser<WarrantRuleNode>
 */
final class ParseUnconditionalRule extends Parser
{
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): WarrantRuleNode|NoMatch
    {
        if (! $this->check(TokenType::THEY)) {
            return self::NOTHING;
        }

        [$canClauses, $cannotClauses] = $this->parse(new ParseTheyCanAndCannotClauses($this->naming))->value;

        return new WarrantRuleNode(null, $canClauses, $cannotClauses);
    }
}
