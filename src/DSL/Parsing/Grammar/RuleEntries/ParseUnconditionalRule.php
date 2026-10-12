<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A rule with no `if`: a run of `they can` / `they cannot` clauses, which form
 * one unconditional rule.
 *
 * @extends Parser<WarrantRuleNode>
 */
final class ParseUnconditionalRule extends Parser
{
    protected function read(): WarrantRuleNode|CouldNotParse
    {
        if (! $this->check(TokenType::THEY)) {
            return self::COULD_NOT_PARSE;
        }

        [$canClauses, $cannotClauses] = $this->parse(new ParseTheyCanAndCannotClauses)->value;

        return new WarrantRuleNode(null, $canClauses, $cannotClauses);
    }
}
