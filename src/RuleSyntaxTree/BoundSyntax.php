<?php

namespace Warrant\RuleSyntaxTree;

/**
 * A rule (or rule set) rendered back to the string DSL with every condition
 * parameter extracted as a positional `?` placeholder, paired with the flat,
 * left-to-right list of values that fill them.
 *
 * Round-trips through the parser: for a rule set,
 * `WarrantRuleSet::fromSyntax($syntax, bindings: $bindings)` reconstructs an
 * equivalent set (the schema rides in the rendered `for` header); for a single
 * rule, `WarrantRule::fromSyntax($syntax, bindings: $bindings)`.
 */
readonly class BoundSyntax
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public string $syntax,
        public array $bindings,
    ) {
    }
}
