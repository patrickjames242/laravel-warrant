<?php

namespace Warrant\Schema\Concerns;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ComputedAbilityContext;

/**
 * Evaluates computed abilities (`#[ComputedAbility]` methods) — the imperative,
 * no-target counterpart to the SQL-compiled abilities in {@see BuildsAccessQueries}.
 * A computed ability's method returns `bool` or a `Response`; this normalizes
 * that into an allow/deny `Response`.
 */
trait ResolvesComputedAbilities
{
    /**
     * The subset of the *named* computed abilities the user holds. Enforces
     * schema-wide required context and each ability's own required context (a named
     * check throws on a missing key), then runs each and keeps the allowed ones.
     * Order follows the input. The caller combines this with the compiled half
     * under the match mode.
     *
     * @param array<int, string> $abilities
     * @return array<int, string>
     */
    protected function heldComputedAbilities(Authenticatable $user, array $abilities, array $context): array
    {
        $context = $this->resolveEffectiveContext($context);
        static::assertAbilitiesHaveRequiredContext($abilities, $context);

        return array_values(array_filter(
            $abilities,
            fn (string $ability): bool => $this->evaluateComputedAbility($ability, $user, $context)->allowed(),
        ));
    }

    /**
     * Run one computed ability's method against an already-effective context and
     * normalize its return (`Response` as-is; `true` → allow; anything else → deny).
     *
     * @param array<string, mixed> $effectiveContext
     */
    protected function evaluateComputedAbility(string $ability, Authenticatable $user, array $effectiveContext): Response
    {
        $definition = collect(static::abilityDefinitions())
            ->first(fn (AbilityDefinition $a): bool => $a->computed && $a->name === $ability);

        $result = $this->{$definition->method}(new ComputedAbilityContext($user, $effectiveContext));

        return $result instanceof Response
            ? $result
            : ($result === true ? Response::allow() : Response::deny());
    }
}
