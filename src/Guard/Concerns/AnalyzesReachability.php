<?php

namespace Warrant\Guard\Concerns;

use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\ReachabilityAnalyzer;
use Warrant\DSL\Expanding\ExpandedRuleSet;
use Warrant\Reachability;

/**
 * "Could they ever?" analysis for the guard's user — answered from the rules
 * alone, with no row, no context and no query. Row and global conditions are
 * never evaluated; constants, derived conditions that answer one, and every
 * `can(...)` the rules lean on are, so an ability granted only on another the
 * user can never hold comes back NEVER. It asks whether a grant is even
 * conceivable, so it is ideal for hiding UI, gating sections, and
 * short-circuiting per-row checks — never a substitute for the real row check.
 */
trait AnalyzesReachability
{
    /**
     * The reachability of a single ability for the guard's user.
     */
    public function reachabilityOf(string $ability): Reachability
    {
        return $this->reachabilityMap($this->schema->normalizeAbilities($ability))[$ability];
    }

    /**
     * Map every requested ability (default: all declared abilities) to its
     * reachability for the guard's user.
     *
     * @param array<int, string>|null $abilities
     * @return array<string, Reachability>
     */
    public function reachabilityMap(?array $abilities = null): array
    {
        $abilities = $abilities === null ? $this->schema::abilityNames() : $this->schema->normalizeAbilities($abilities);

        /* The analysis reads the expanded rule set, not the one as written: an
           ability granted only through a template, or hinging on a derived
           condition, would otherwise be judged on what the author abbreviated.
           One analyzer serves the whole map, so an ability several others lean
           on through `can(...)` is analyzed once. */
        $analyzer = new ReachabilityAnalyzer($this->reachabilityRuleSetFor(...));

        $map = [];
        foreach ($abilities as $ability) {
            $map[$ability] = $analyzer->analyze($this->expandedRuleSet(), $ability);
        }

        return $map;
    }

    /**
     * The expanded rule set this guard's user is given for $schemaKey — the
     * guard's own, or the one the user's guard for another schema resolves, which
     * is where a `can(... for <schema>)` in the rules leads. Null for a schema
     * nobody registered, which validation reports.
     */
    private function reachabilityRuleSetFor(string $schemaKey): ?ExpandedRuleSet
    {
        if ($schemaKey === $this->schema::schemaKey()) {
            return $this->expandedRuleSet();
        }

        $schemaClass = $this->manager->registry()->resolveSchemaClassOrNull($schemaKey);

        return $schemaClass === null
            ? null
            : $this->manager->forSchema($schemaClass, $this->user)->expandedRuleSet();
    }

    /**
     * Whether the requested abilities collectively satisfy the predicate under the
     * match mode: ALL requires every ability to pass, ANY requires at least one.
     *
     * @param string|array<int, string> $abilities
     * @param callable(Reachability): bool $passes
     */
    public function reachabilitySatisfies(
        string|array $abilities,
        callable $passes,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        $map = $this->reachabilityMap($this->schema->normalizeAbilities($abilities));

        if ($map === []) {
            return false;
        }

        foreach ($map as $reachability) {
            $passed = $passes($reachability);

            if ($matchMode === AbilityMatchMode::ALL && ! $passed) {
                return false;
            }

            if ($matchMode === AbilityMatchMode::ANY && $passed) {
                return true;
            }
        }

        return $matchMode === AbilityMatchMode::ALL;
    }

    /**
     * The declared abilities whose reachability passes the predicate.
     *
     * @param callable(Reachability): bool $passes
     * @return array<int, string>
     */
    public function abilitiesWhereReachability(callable $passes): array
    {
        return array_keys(array_filter(
            $this->reachabilityMap(),
            fn (Reachability $r): bool => $passes($r),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Reachability predicates — "could this user ever hold the ability?"
    |--------------------------------------------------------------------------
    |
    | ALL vs ANY is expressed by the method name (…/…Any), matching the check
    | surface (can/canAny); no match-mode argument is exposed.
    */

    /**
     * Whether the user could ever hold every requested ability under some
     * circumstance (reachability is not NEVER).
     *
     * @param string|array<int, string> $abilities
     */
    public function couldEverHave(string|array $abilities): bool
    {
        return $this->reachabilitySatisfies($abilities, fn (Reachability $r): bool => $r !== Reachability::NEVER);
    }

    /**
     * Whether the user could ever hold at least one of the requested abilities.
     *
     * @param string|array<int, string> $abilities
     */
    public function couldEverHaveAny(string|array $abilities): bool
    {
        return $this->reachabilitySatisfies(
            $abilities,
            fn (Reachability $r): bool => $r !== Reachability::NEVER,
            AbilityMatchMode::ANY,
        );
    }

    /**
     * Whether the user is guaranteed every requested ability regardless of the row
     * (reachability is ALWAYS).
     *
     * @param string|array<int, string> $abilities
     */
    public function alwaysHas(string|array $abilities): bool
    {
        return $this->reachabilitySatisfies($abilities, fn (Reachability $r): bool => $r === Reachability::ALWAYS);
    }

    /**
     * Whether the user is guaranteed at least one of the requested abilities.
     *
     * @param string|array<int, string> $abilities
     */
    public function alwaysHasAny(string|array $abilities): bool
    {
        return $this->reachabilitySatisfies(
            $abilities,
            fn (Reachability $r): bool => $r === Reachability::ALWAYS,
            AbilityMatchMode::ANY,
        );
    }

    /**
     * Whether the user can never hold any of the requested abilities under any
     * circumstance (reachability is NEVER for all).
     *
     * @param string|array<int, string> $abilities
     */
    public function neverHas(string|array $abilities): bool
    {
        return $this->reachabilitySatisfies($abilities, fn (Reachability $r): bool => $r === Reachability::NEVER);
    }

    /**
     * Whether the user can never hold at least one of the requested abilities.
     *
     * @param string|array<int, string> $abilities
     */
    public function neverHasAny(string|array $abilities): bool
    {
        return $this->reachabilitySatisfies(
            $abilities,
            fn (Reachability $r): bool => $r === Reachability::NEVER,
            AbilityMatchMode::ANY,
        );
    }

    /**
     * Every declared ability the user could ever hold (reachability not NEVER).
     *
     * @return array<int, string>
     */
    public function possibleAbilities(): array
    {
        return $this->abilitiesWhereReachability(fn (Reachability $r): bool => $r !== Reachability::NEVER);
    }

    /**
     * Every declared ability the user is guaranteed (reachability ALWAYS).
     *
     * @return array<int, string>
     */
    public function guaranteedAbilities(): array
    {
        return $this->abilitiesWhereReachability(fn (Reachability $r): bool => $r === Reachability::ALWAYS);
    }

    /**
     * Every declared ability the user can never hold (reachability NEVER).
     *
     * @return array<int, string>
     */
    public function impossibleAbilities(): array
    {
        return $this->abilitiesWhereReachability(fn (Reachability $r): bool => $r === Reachability::NEVER);
    }
}
