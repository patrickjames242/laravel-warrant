<?php

namespace Warrant\DSL\Compiling;

use Warrant\Reachability;
use Warrant\Rules\WarrantRuleSet;

/**
 * Answers "could a user ever hold ability X?" by inspecting the *structure* of a
 * resolved {@see WarrantRuleSet} — never by evaluating conditions or running SQL.
 *
 * Each rule listing the ability (directly or via `*`) is classified only by
 * whether it is unconditional (a null condition expression). The outcome follows
 * a fixed decision table, resolved top to bottom:
 *
 *   1. an unconditional `cannot`            → NEVER  (undodgeable deny wins)
 *   2. no `can` rule lists the ability      → NEVER  (no grant path at all)
 *   3. an unconditional `can`, no cond. deny → ALWAYS (granted, nothing strips it)
 *   4. otherwise                            → MAYBE  (a condition decides)
 *
 * A *conditional* `cannot` is intentionally ignored: it can always be dodged by a
 * different row/state, so it never lowers certainty. Only an unconditional
 * `cannot` — which the compiler already treats as a hard `1 = 0` — makes us sure.
 */
final class ReachabilityAnalyzer
{
    public function analyze(WarrantRuleSet $ruleSet, string $ability): Reachability
    {
        $hasUnconditionalCannot = false;
        $hasUnconditionalCan = false;
        $hasConditionalCan = false;
        $hasConditionalCannot = false;

        foreach ($ruleSet->rules as $rule) {
            $isUnconditional = $rule->conditions === null;

            if ($this->listsAbility($rule->cannotAbilities(), $ability)) {
                $isUnconditional ? $hasUnconditionalCannot = true : $hasConditionalCannot = true;
            }

            if ($this->listsAbility($rule->canAbilities, $ability)) {
                $isUnconditional ? $hasUnconditionalCan = true : $hasConditionalCan = true;
            }
        }

        if ($hasUnconditionalCannot) {
            return Reachability::NEVER;
        }

        if (! $hasUnconditionalCan && ! $hasConditionalCan) {
            return Reachability::NEVER;
        }

        if ($hasUnconditionalCan && ! $hasConditionalCannot) {
            return Reachability::ALWAYS;
        }

        return Reachability::MAYBE;
    }

    /**
     * @param array<int, string> $abilities
     */
    private function listsAbility(array $abilities, string $ability): bool
    {
        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }
}
