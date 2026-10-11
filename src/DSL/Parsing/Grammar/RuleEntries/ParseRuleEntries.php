<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;
use Warrant\DSL\Parsing\Parsers\ParseOrSkipToRestart;
use Warrant\DSL\Parsing\Parsers\Parser;

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
 * In an analysis, an entry with an error is reported and stepped over, to the
 * next token that starts an entry or ends the body, and the entries around it
 * are read as they would be without it.
 *
 * @extends Parser<list<IRuleEntryNode>>
 */
final class ParseRuleEntries extends Parser
{
    use LooksAheadAtRuleEntries;

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
        $entries = $this->parseRepeated(new ParseOrSkipToRestart(
            new ParseOneOf([
                new ParseUnconditionalRule($this->naming),
                new ParseIncludeInvocation($this->naming),
                new ParseConditionalRule($this->naming),
                new ParseAbilityBlock($this->naming),
            ]),
            fn (): bool => $this->ruleEntryAhead() || $this->check(TokenType::RBRACE) || $this->check(TokenType::FOR),
        ))?->value ?? [];

        $this->assertNoAbilityListWithoutCanThey();

        return array_values(array_filter($entries, static fn (mixed $entry): bool => $entry !== self::SKIPPED));
    }
}
