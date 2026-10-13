<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\Grammar\RuleEntries\ParseRuleEntries;
use Warrant\DSL\Parsing\Grammar\RuleEntries\ParseRuleEntry;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * Rule entries written with no `for` header, running to the end of the input.
 *
 * They either all name their abilities, as a provider's rules do, or all leave
 * them to be named where the rules are placed, as a rule template's body does;
 * entries that do not agree are the validator's to report.
 *
 * @extends Parser<list<IRuleEntryNode>>
 */
final class ParseRulesWithoutForHeader extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return ParseRuleEntry::startsHere($ahead);
    }

    /**
     * @return list<IRuleEntryNode>
     */
    protected function read(): array
    {
        $entries = $this->parse(ParseRuleEntries::class)->value;

        if ($this->check(TokenType::FOR)) {
            throw $this->errorAtCurrent(
                'Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own.'
            );
        }

        return $entries;
    }
}
