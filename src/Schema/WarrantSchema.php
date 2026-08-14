<?php

namespace Warrant\Schema;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\RuleSyntaxTree\ConditionResolver;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\Schema\Concerns\AnalyzesReachability;
use Warrant\Schema\Concerns\BuildsAccessQueries;
use Warrant\Schema\Concerns\DiagnosesDenials;
use Warrant\Schema\Concerns\ReflectsSchemaDefinition;
use Warrant\Schema\Concerns\ResolvesConditions;
use Warrant\Schema\Concerns\ResolvesRuleSets;
use Warrant\WarrantAuthorizationException;
use Warrant\WarrantDenialContext;
use Warrant\WarrantUngrantedContext;

/**
 * A Warrant schema declares the vocabulary a rule string may reference for one
 * entity: its abilities (`#[Ability]` constants) and its conditions
 * (`#[TargetedCondition]` / `#[GlobalCondition]` methods, which emit SQL). It is NOT where the
 * rules live — those come from the {@see RuleResolver} as a
 * {@see \Warrant\RuleSyntaxTree\WarrantRuleSet}, compiled against this schema.
 *
 * The implementation is split across concerns:
 *  - {@see ReflectsSchemaDefinition} — discovering abilities/conditions via reflection;
 *  - {@see ResolvesConditions}       — the ConditionResolver seam + ability validation;
 *  - {@see ResolvesRuleSets}         — resolving the ordered rule set (resolver + implicit rules);
 *  - {@see BuildsAccessQueries}      — turning a rule set into SQL access predicates;
 *  - {@see DiagnosesDenials}         — turning a denied check into a denial message/exception;
 *  - {@see AnalyzesReachability}     — the structural "could they ever?" analysis.
 *
 * This class itself carries the configuration constants, the instance lifecycle,
 * and the static entry points callers reach for.
 */
abstract class WarrantSchema implements ConditionResolver
{
    use ReflectsSchemaDefinition;
    use ResolvesConditions;
    use ResolvesRuleSets;
    use BuildsAccessQueries;
    use DiagnosesDenials;
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
     *         WarrantRule::fromSyntax('if is_super_admin they can *'),
     *         WarrantRule::fromSyntax('if is_suspended they cannot *'),
     *     ];
     * }
     * ```
     *
     * @return array<int, WarrantRule>
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
     * Assert the user holds the abilities, throwing on denial instead of
     * returning a bool. The throwing sibling of {@see userHasAbilities}.
     *
     * The denial is diagnosed and the first message source that speaks wins: the
     * responsible `cannot` rule's own message, then the schema's
     * {@see forbiddenDenialMessage} (a forbid with no rule message), then
     * {@see ungrantedDenialMessage} (no grant), then a generic
     * {@see WarrantAuthorizationException} (403). Diagnosis works for a singular
     * target (a `Model` or key) and for a no-target check — in the latter only
     * global/unconditional `cannot` rules can be the cause, since a targeted
     * condition cannot fire without a row.
     *
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    public static function authorize(
        string|array $abilities,
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): void
    {
        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                sprintf('Schema [%s] requires an authenticated user or an explicit user instance.', static::class)
            );
        }

        if (static::userHasAbilities($abilities, $target, $user, $matchMode, $context)) {
            return;
        }

        throw (new static)->diagnoseDenial($user, $abilities, $target, $matchMode, $context)
            ?? new WarrantAuthorizationException;
    }

    /**
     * The schema-level fallback message for a *forbidden* denial — a matching
     * `cannot` rule blocked the check but carried no {@see \Warrant\RuleSyntaxTree\WarrantRule::$message}
     * of its own. Consulted after a rule's own message and before the generic 403,
     * so it catches every message-less `cannot`.
     *
     * The {@see WarrantDenialContext} carries the responsible `rule` and the gate
     * abilities it blocked. Return a string (wrapped in a
     * {@see WarrantAuthorizationException} → 403), a `Throwable` (thrown as-is), or
     * null to fall through (to {@see ungrantedDenialMessage} if some ability was
     * also ungranted, otherwise the generic 403).
     */
    protected function forbiddenDenialMessage(WarrantDenialContext $context): string|\Throwable|null
    {
        return null;
    }

    /**
     * The message for a denial caused by the *absence of a grant* — the user was
     * neither forbidden by a `cannot` nor allowed by a `can`. This is distinct
     * from being forbidden: a `cannot` that blocks the check is handled by a
     * rule's own message or {@see forbiddenDenialMessage}, never here.
     *
     * Return a string (wrapped in a {@see WarrantAuthorizationException} → 403), a
     * `Throwable` (thrown as-is), or null to keep the generic default. The
     * {@see WarrantUngrantedContext} carries the gate and the ungranted abilities,
     * so the message can speak to the whole request (e.g. "you need at least one
     * of …" under `ANY`).
     */
    protected function ungrantedDenialMessage(WarrantUngrantedContext $context): string|\Throwable|null
    {
        return null;
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

}
