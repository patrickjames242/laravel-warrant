<?php

namespace Warrant\DSL\Compiling\Units;

use Warrant\Rules\WarrantRuleSet;

/**
 * One ability, resolved against a rule set under the deny-overrides formula.
 *
 * This is the unit a gate ORs or ANDs, and the unit a cross-schema `can(...)`
 * splices in, so a constant folds across both boundaries instead of stopping at
 * a `1 = 1`.
 */
final readonly class AbilityUnit implements CompilationUnit
{
    public function __construct(
        public string $ability,
        public WarrantRuleSet $ruleSet,
    ) {
    }
}
