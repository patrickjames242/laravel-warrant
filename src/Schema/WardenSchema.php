<?php

namespace Warden\Schema;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warden\AbilityMatchMode;
use Warden\Reachability;
use Warden\RuleSyntaxTree\ConditionResolver;
use Warden\RuleSyntaxTree\WardenRule;
use Warden\Schema\Concerns\AnalyzesReachability;
use Warden\Schema\Concerns\BuildsAccessQueries;
use Warden\Schema\Concerns\ReflectsSchemaDefinition;
use Warden\Schema\Concerns\ResolvesConditions;

/**
 * A Warden schema declares the vocabulary a rule string may reference for one
 * entity: its abilities (`#[Ability]` constants) and its conditions
 * (`#[TargetedCondition]` / `#[GlobalCondition]` methods, which emit SQL). It is NOT where the
 * rules live — those come from the {@see RuleResolver} as a
 * {@see \Warden\RuleSyntaxTree\WardenRuleSet}, compiled against this schema.
 *
 * The implementation is split across four concerns:
 *  - {@see ReflectsSchemaDefinition} — discovering abilities/conditions via reflection;
 *  - {@see ResolvesConditions}       — the ConditionResolver seam + ability validation;
 *  - {@see BuildsAccessQueries}      — turning a rule set into SQL access predicates;
 *  - {@see AnalyzesReachability}     — the structural "could they ever?" analysis.
 *
 * This class itself carries the configuration constants, the instance lifecycle,
 * and the static entry points callers reach for.
 */
abstract class WardenSchema implements ConditionResolver
{
    use ReflectsSchemaDefinition;
    use ResolvesConditions;
    use BuildsAccessQueries;
    use AnalyzesReachability;

    /**
     * @var class-string<Model>
     */
    public const model = '';

    /**
     * Explicit schema key to override the default. When null the schema key is
     * derived from the model table.
     */
    public const schemaKey = null;

    /**
     * @var array<string, true>
     */
    private array $abilityLookup;

    public function __construct()
    {
        $this->abilityLookup = array_fill_keys(static::declaredAbilities(), true);
    }

    /**
     * Rules that are always in force for this schema, regardless of what the
     * resolver returns. They are merged into every resolved rule set before
     * compilation, so they are validated and compiled exactly like resolver
     * rules (deny-overrides still applies across both).
     *
     * Override to establish baseline access — e.g. a super-admin escape hatch or
     * a universal deny:
     *
     * ```php
     * protected function implicitRules(): array
     * {
     *     return [
     *         WardenRule::fromSyntax('if is_super_admin they can *'),
     *         WardenRule::fromSyntax('if is_suspended they cannot *'),
     *     ];
     * }
     * ```
     *
     * @return array<int, WardenRule>
     */
    protected function implicitRules(): array
    {
        return [];
    }

    /**
     * Default check-time context for this schema, merged *under* any context
     * passed explicitly to a check (explicit values win; partial explicit context
     * is allowed). Override to source the frame from the request/tenant/container
     * so that param-less entry points — route middleware and the auto-applied
     * `SelectAbilities` global scope — receive context without a `context:`
     * argument:
     *
     * ```php
     * protected function defaultContext(): array
     * {
     *     return ['workspace_id' => app('tenant')->id];
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    protected function defaultContext(): array
    {
        return [];
    }

    public static function userHasAbilities(
        string|array $abilities,
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): bool
    {
        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                sprintf('Schema [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        $schema = new static;

        if ($target === null) {
            return $schema->getAbilitiesWithoutTarget($user, $abilities, $matchMode, $context) !== [];
        }

        static::assertSupportsTargetedChecks();

        /** @var Model $model */
        $model = new (static::model);
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        return $schema->filterQuery(
            currentUser: $user,
            query: $model->newQuery()->whereKey($targetId)->getQuery(),
            targetSqlId: $model->getQualifiedKeyName(),
            abilities: $abilities,
            matchMode: $matchMode,
            context: $context,
        )->exists();
    }

    /**
     * @return array<int, string>
     */
    public static function getUserAbilities(
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        array $context = []
    ): array
    {
        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                sprintf('Schema [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        $schema = new static;

        if ($target === null) {
            return $schema->getAbilitiesWithoutTarget($user, context: $context);
        }

        static::assertSupportsTargetedChecks();

        /** @var Model $model */
        $model = new (static::model);
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        // selectAbilitiesInQuery adds the abilities list via selectSub aliased
        // AS abilities — NOT a real column on the underlying table. Using
        // ->value('abilities') here would call Laravel's first(['abilities']),
        // whose onceWithColumns mechanism replaces the SELECT clause with
        // ['abilities'], wiping the selectSub and yielding null. Read the
        // hydrated row instead so the alias survives.
        $row = (array)$schema->selectAbilitiesInQuery(
            currentUser: $user,
            query: $model->newQuery()->whereKey($targetId)->getQuery(),
            targetSqlId: $model->getQualifiedKeyName(),
            context: $context,
        )->first();
        $selectedAbilities = $row['abilities'] ?? null;

        if (is_array($selectedAbilities)) {
            return $selectedAbilities;
        }

        if (!is_string($selectedAbilities) || $selectedAbilities === '') {
            return [];
        }

        $decodedSelectedAbilities = json_decode($selectedAbilities, true);

        return is_array($decodedSelectedAbilities) ? $decodedSelectedAbilities : [];
    }

    /**
     * Returns the no-target access-control bag using the same nested shape as
     * resource helpers.
     *
     * @return array<string, array{schema_key: string, abilities: array<int, string>, target: null}>
     */
    public static function getNoTargetAbilitiesBag(?Authenticatable $user = null): array
    {
        return [
            'schema_key' => static::schemaKey(),
            'abilities' => static::getUserAbilities(null, $user),
            'target' => null,
        ];
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
