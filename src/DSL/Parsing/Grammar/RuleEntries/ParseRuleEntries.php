<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The entries of a rule body: unconditional rules, `if` rules, ability blocks
 * and `@include` directives, in any order, up to where the body ends.
 *
 * What comes back is one list in source order. An include keeps its place
 * among the rules because expansion splices the template's rules in where it
 * was written, so the position is part of what the include means; a block
 * keeps its place as an {@see \Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode}
 * holding its own entries.
 *
 * A token between entries that starts none is the mistake of whatever reads the
 * body, and is reported with that reader's error. A tolerant parse reads on past
 * it, and past a mistake inside an entry, to the next place an entry can start,
 * so the entries after a mistake are still read.
 *
 * @extends Parser<list<IRuleEntryNode>>
 */
final class ParseRuleEntries extends Parser
{
    use LooksAheadAtRuleEntries;

    /**
     * @param string $unexpectedToken The error for a token between entries that
     *   starts none: the one the reader of the body reports there, as `}` or the
     *   end of the input should be there instead.
     */
    public function __construct(private readonly string $unexpectedToken)
    {
    }

    /**
     * @return list<IRuleEntryNode>
     */
    protected function read(): array
    {
        $entries = [];

        while (! self::ruleEntriesEndHere($this->tokensAhead())) {
            if (! $this->ruleEntryAhead()) {
                $this->assertNoAbilityListWithoutCanThey();
                $this->report($this->errorAtCurrent($this->unexpectedToken));
                $this->skipTo(self::ruleEntriesResumeHere(...), atLeastOne: true);

                continue;
            }

            $entry = $this->recovering(
                fn () => $this->parse(ParseRuleEntry::class)->value,
                self::ruleEntriesResumeHere(...),
            );

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
