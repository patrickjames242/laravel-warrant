<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;

/**
 * The entries of a rule body: unconditional rules, `if` rules, ability blocks
 * and `@include` directives, in any order, for as long as one starts.
 *
 * What comes back is one list in source order. An include keeps its place
 * among the rules because expansion splices the template's rules in where it
 * was written, so the position is part of what the include means; a block
 * keeps its place as an {@see \Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode}
 * holding its own entries.
 *
 * @extends GrammarParser<list<IRuleEntryNode>>
 */
final class ParseRuleEntries extends GrammarParser
{
    /**
     * @param AbilityNaming $naming Whether clauses and includes in this body name
     *   their abilities.
     */
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    /**
     * @return list<IRuleEntryNode>
     */
    protected function read(): array
    {
        $entry = new ParseOneOf([
            new ParseUnconditionalRule($this->naming),
            new ParseIncludeInvocation($this->naming),
            new ParseConditionalRule($this->naming),
            new ParseAbilityBlock($this->naming),
        ]);

        $entries = [];

        while (($next = $this->parse($entry)) !== null) {
            $entries[] = $next->value;
        }

        $this->assertNoAbilityListWithoutCanThey();

        return $entries;
    }
}
