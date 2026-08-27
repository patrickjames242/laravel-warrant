<?php

namespace Warrant\Guard\Concerns;

use Warrant\AbilityMatchMode;
use Warrant\Reachability;
use Warrant\RuleSyntaxTree\ReachabilityAnalyzer;

/**
 * Structural "could they ever?" analysis for the guard's user — answered from the
 * shape of the resolved rules alone: no conditions are evaluated and no query is
 * run. It asks whether a grant is even conceivable, so it is ideal for hiding UI,
 * gating sections, and short-circuiting per-row checks — never a substitute for
 * the real row check.
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

        $ruleSet = $abilities === [] ? null : $this->resolvedRuleSet();
        $analyzer = new ReachabilityAnalyzer;

        $map = [];
        foreach ($abilities as $ability) {
            $map[$ability] = $analyzer->analyze($ruleSet, $ability);
        }

        return $map;
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
