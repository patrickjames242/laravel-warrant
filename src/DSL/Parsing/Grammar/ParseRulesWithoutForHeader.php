<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\Grammar\RuleEntries\LooksAheadAtRuleEntries;
use Warrant\DSL\Parsing\Grammar\RuleEntries\ParseRuleEntries;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * Rule entries written with no `for` header, running to the end of the input;
 * {@see COULD_NOT_PARSE} when no rule entry starts here.
 *
 * They either all name their abilities, as a provider's rules do, or all leave
 * them to be named where the rules are placed, as a rule template's body does;
 * entries that do not agree are the validator's to report.
 *
 * @extends Parser<list<IRuleEntryNode>>
 */
final class ParseRulesWithoutForHeader extends Parser
{
    use LooksAheadAtRuleEntries;

    /**
     * @return list<IRuleEntryNode>|CouldNotParse
     */
    protected function read(): array|CouldNotParse
    {
        if (! $this->ruleEntryAhead()) {
            return self::COULD_NOT_PARSE;
        }

        $entries = $this->parse(ParseRuleEntries::class)->value;

        if ($this->check(TokenType::FOR)) {
            throw $this->errorAtCurrent(
                'Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own.'
            );
        }

        return $entries;
    }
}
