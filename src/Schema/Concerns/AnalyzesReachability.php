<?php

namespace Warrant\Schema\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
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
}
