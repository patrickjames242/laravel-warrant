<?php

namespace Warrant\Schema\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\Reachability;
use Warrant\RuleSyntaxTree\ReachabilityAnalyzer;

/**
 * The structural runtime: answers "could this user ever hold ability X?" purely
 * from the shape of their resolved rule set — no conditions evaluated, no SQL.
 *
 * Unlike {@see BuildsAccessQueries}, nothing here touches a database connection
 * or the check-time context: `@context` and condition SQL only matter when a
 * condition is actually evaluated, and reachability never evaluates one. The user
 * is still required, because the {@see \Warrant\RuleResolver} may hand a different
 * rule set to each user/role/tenant.
 *
 * The `public function` methods are the analysis engine; the `public static`
 * methods below are the entry points callers reach for.
 */
trait AnalyzesReachability
{
    /**
     * The reachability of a single ability for the given user.
     */
    public function reachabilityOf(Authenticatable $currentUser, string $ability): Reachability
    {
        return $this->reachabilityMap($currentUser, $this->normalizeAbilities($ability))[$ability];
    }

    /**
     * Map every requested ability (default: all declared abilities) to its
     * reachability for the given user.
     *
     * @param array<int, string> $abilities
     * @return array<string, Reachability>
     */
    public function reachabilityMap(Authenticatable $currentUser, ?array $abilities = null): array
    {
        $abilities = $abilities === null ? static::declaredAbilities() : $this->normalizeAbilities($abilities);

        $ruleSet = $this->resolveRuleSet($currentUser);
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
        Authenticatable $currentUser,
        string|array $abilities,
        callable $passes,
        AbilityMatchMode $matchMode,
    ): bool {
        $map = $this->reachabilityMap($currentUser, $this->normalizeAbilities($abilities));

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
    public function abilitiesWhereReachability(Authenticatable $currentUser, callable $passes): array
    {
        return array_keys(array_filter(
            $this->reachabilityMap($currentUser),
            fn (Reachability $r): bool => $passes($r),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Reachability — "could this user ever hold the ability?"
    |--------------------------------------------------------------------------
    |
    | A structural question answered from the shape of the resolved rules alone:
    | no conditions are evaluated and no query is run. It asks whether a grant is
    | even conceivable, so it is ideal for hiding UI, gating sections, and short-
    | circuiting per-row checks — never a substitute for the real row check.
    */

    /**
     * Classify one ability as NEVER / MAYBE / ALWAYS for the user.
     */
    public static function abilityReachability(string $ability, ?Authenticatable $user = null): Reachability
    {
        return (new static)->reachabilityOf(static::resolveReachabilityUser($user), $ability);
    }

    /**
     * Whether the user could ever hold the ability under some circumstance
     * (reachability is not NEVER). With several abilities, the match mode decides:
     * ALL requires every ability to be reachable, ANY requires at least one.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userCouldEverHave(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->reachabilitySatisfies(
            static::resolveReachabilityUser($user),
            $abilities,
            fn (Reachability $r): bool => $r !== Reachability::NEVER,
            $matchMode,
        );
    }

    /**
     * Whether the user is guaranteed the ability regardless of the row
     * (reachability is ALWAYS). See {@see userCouldEverHave} for match-mode rules.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userAlwaysHas(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->reachabilitySatisfies(
            static::resolveReachabilityUser($user),
            $abilities,
            fn (Reachability $r): bool => $r === Reachability::ALWAYS,
            $matchMode,
        );
    }

    /**
     * Whether the user can never hold the ability under any circumstance
     * (reachability is NEVER). See {@see userCouldEverHave} for match-mode rules.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userNeverHas(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->reachabilitySatisfies(
            static::resolveReachabilityUser($user),
            $abilities,
            fn (Reachability $r): bool => $r === Reachability::NEVER,
            $matchMode,
        );
    }

    /**
     * Every declared ability the user could ever hold (reachability not NEVER).
     *
     * @return array<int, string>
     */
    public static function getUserPossibleAbilities(?Authenticatable $user = null): array
    {
        return (new static)->abilitiesWhereReachability(
            static::resolveReachabilityUser($user),
            fn (Reachability $r): bool => $r !== Reachability::NEVER,
        );
    }

    /**
     * Every declared ability the user is guaranteed (reachability ALWAYS).
     *
     * @return array<int, string>
     */
    public static function getUserGuaranteedAbilities(?Authenticatable $user = null): array
    {
        return (new static)->abilitiesWhereReachability(
            static::resolveReachabilityUser($user),
            fn (Reachability $r): bool => $r === Reachability::ALWAYS,
        );
    }

    /**
     * Every declared ability the user can never hold (reachability NEVER).
     *
     * @return array<int, string>
     */
    public static function getUserImpossibleAbilities(?Authenticatable $user = null): array
    {
        return (new static)->abilitiesWhereReachability(
            static::resolveReachabilityUser($user),
            fn (Reachability $r): bool => $r === Reachability::NEVER,
        );
    }

    private static function resolveReachabilityUser(?Authenticatable $user): Authenticatable
    {
        $user ??= auth()->user();

        if (! $user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                sprintf('Schema [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        return $user;
    }
}
