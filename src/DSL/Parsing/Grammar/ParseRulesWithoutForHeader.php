<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\Grammar\RuleEntries\AbilityNaming;
use Warrant\DSL\Parsing\Grammar\RuleEntries\ParseRuleEntries;
use Warrant\DSL\Parsing\Grammar\RuleEntries\ParseWhetherRuleEntryNamesAbilities;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * Rule entries written with no `for` header, running to the end of the input;
 * {@see NOTHING} when no rule entry starts here.
 *
 * They either all name their abilities, as a provider's rules do, or all leave
 * them to be named where the rules are placed, as a rule template's body does.
 * The first entry decides which, and the entries are read held to it.
 *
 * @extends Parser<list<IRuleEntryNode>>
 */
final class ParseRulesWithoutForHeader extends Parser
{
    /**
     * @return list<IRuleEntryNode>|NoMatch
     */
    protected function read(): array|NoMatch
    {
        $names = $this->lookahead(ParseWhetherRuleEntryNamesAbilities::class);

        if ($names === null) {
            return self::NOTHING;
        }

        $entries = $this->parse(new ParseRuleEntries(AbilityNaming::likeFirstEntry($names->value)))->value;

        if ($this->check(TokenType::FOR)) {
            $this->report($this->errorAtCurrent(
                'Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own.'
            ));
        }

        return $entries;
    }
}
