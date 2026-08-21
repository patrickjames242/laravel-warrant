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
use Warrant\Schema\Concerns\ResolvesComputedAbilities;
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
    use ResolvesComputedAbilities;
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
        /* Every declared ability — computed and compiled alike — is a valid name to
           *reference* in a check or reachability query; the SQL-only paths (query
           scopes) reject computed names explicitly with a clearer message. */
        $this->abilityLookup = array_fill_keys(
            collect(static::abilityDefinitions())->map(fn ($a): string => $a->name)->all(),
            true,
        );
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
     * so that param-less entry points — route middleware and the
     * `userHasAbility` / `selectUserAbilities` query scopes — receive context
     * without a `context:` argument:
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
        $target = $schema->resolveCheckTarget($target);
        $split = $schema->splitRequestedAbilities($abilities, $target);

        if ($target !== null) {
            /* A concrete target makes this a row check: computed abilities are
               excluded by splitRequestedAbilities, so only compiled abilities remain. */
            static::assertSupportsTargetedChecks();

            /** @var Model $model */
            $model = new (static::model);
            $targetId = $target instanceof Model ? $target->getKey() : $target;

            return $schema->filterQuery(
                currentUser: $user,
                query: $model->newQuery()->whereKey($targetId)->getQuery(),
                targetSqlId: $model->getQualifiedKeyName(),
                abilities: $split['sql'],
                matchMode: $matchMode,
                context: $context,
            )->exists();
        }

        /* No target: compiled and computed abilities may be combined. Each side is
           evaluated to the subset the user holds, then the match mode is applied
           across the whole requested set. */
        $held = $schema->evaluateNoTarget($user, $split['sql'], $split['computed'], $context);

        return static::noTargetCheckPasses($split['sql'], $split['computed'], $held, $matchMode);
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

        $schema = new static;
        $target = $schema->resolveCheckTarget($target);
        $split = $schema->splitRequestedAbilities($abilities, $target);

        if (static::userHasAbilities($abilities, $target, $user, $matchMode, $context)) {
            return;
        }

        /* A targeted denial is diagnosed from the rules against the row. A no-target
           denial may be caused by a compiled ability (diagnosed from the rules) or a
           computed ability (whose own Response message is surfaced instead). */
        if ($target === null) {
            $held = $schema->evaluateNoTarget($user, $split['sql'], $split['computed'], $context);

            throw $schema->diagnoseNoTargetDenial($user, $split, $held, $matchMode, $context);
        }

        throw $schema->diagnoseDenial($user, $split['sql'], $target, $matchMode, $context)
            ?? new WarrantAuthorizationException;
    }

    /**
     * Normalize the `$target` argument of a check into either a concrete row
     * (a `Model` instance or a key string) or `null` (a no-target check).
     *
     * A **class-string** is not a row: naming this schema's own model class — or the
     * schema class itself — is how a no-target check is expressed positionally
     * (mirroring the Gate bridge, so `Schema::userHasAbilities('create', Model::class)`
     * reads the same as `can('create', Model::class)`); it resolves to `null`. A
     * class-string for a *different* `Model`/`WarrantSchema` is a mistake — the ability
     * belongs to another schema — and throws. Any other string is a target key and is
     * left untouched for the row path.
     *
     * `null` and a `Model` instance pass straight through.
     */
    private function resolveCheckTarget(Model|string|null $target): Model|string|null
    {
        if ($target === null || $target instanceof Model) {
            return $target;
        }

        if ((static::model !== '' && is_a($target, static::model, true)) || is_a($target, static::class, true)) {
            return null;
        }

        if (is_a($target, Model::class, true) || is_a($target, self::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Target [%s] does not belong to schema [%s]%s; pass this schema\'s model or schema class for a no-target check, or an instance/key for a row check.',
                $target,
                static::class,
                static::model !== '' ? sprintf(' (model [%s])', static::model) : '',
            ));
        }

        return $target;
    }

    /**
     * Split a requested ability list into its computed and compiled (SQL) halves,
     * validating every name is declared and keeping the original request order in
     * `all`. Computed abilities are no-target, so naming one against a concrete
     * target (a `Model` instance or a bare key) is rejected here. `$target` has
     * already passed through {@see resolveCheckTarget}, so a model/schema
     * class-string standing in for a no-target check arrives here as `null`.
     *
     * @param string|array<int, string> $abilities
     * @return array{all: array<int, string>, computed: array<int, string>, sql: array<int, string>}
     */
    private function splitRequestedAbilities(string|array $abilities, Model|string|null $target): array
    {
        $list = $this->normalizeAbilities($abilities);

        $computed = array_values(array_filter($list, fn (string $ability): bool => static::isComputedAbility($ability)));
        $sql = array_values(array_filter($list, fn (string $ability): bool => !static::isComputedAbility($ability)));

        if ($computed !== [] && $target !== null) {
            throw new InvalidArgumentException(sprintf(
                'Computed ability [%s] cannot be checked against a target on schema [%s]; computed abilities are no-target.',
                $computed[0],
                static::class,
            ));
        }

        return ['all' => $list, 'computed' => $computed, 'sql' => $sql];
    }

    /**
     * Evaluate a no-target check's compiled and computed halves independently,
     * returning the subset of each the user holds. The compiled side runs under
     * `ANY` so it yields the held subset rather than an all-or-nothing result; the
     * match mode is applied across the full set by {@see noTargetCheckPasses}.
     *
     * @param array<int, string> $sql
     * @param array<int, string> $computed
     * @return array{sql: array<int, string>, computed: array<int, string>}
     */
    private function evaluateNoTarget(Authenticatable $user, array $sql, array $computed, array $context): array
    {
        return [
            'sql' => $sql === []
                ? []
                : $this->getAbilitiesWithoutTarget($user, $sql, AbilityMatchMode::ANY, $context),
            'computed' => $computed === []
                ? []
                : $this->heldComputedAbilities($user, $computed, $context),
        ];
    }

    /**
     * Whether a no-target check passes given the held subset of each half. An empty
     * request never passes. `ALL` requires every requested ability held; `ANY`
     * requires at least one, across the compiled and computed sets together.
     *
     * @param array<int, string> $sql
     * @param array<int, string> $computed
     * @param array{sql: array<int, string>, computed: array<int, string>} $held
     */
    private static function noTargetCheckPasses(array $sql, array $computed, array $held, AbilityMatchMode $matchMode): bool
    {
        if ($sql === [] && $computed === []) {
            return false;
        }

        if ($matchMode === AbilityMatchMode::ALL) {
            return count($held['sql']) === count($sql) && count($held['computed']) === count($computed);
        }

        return $held['sql'] !== [] || $held['computed'] !== [];
    }

    /**
     * Build the exception for a denied no-target check. Picks the responsible
     * ability — under `ALL` the first failing one in request order; under `ANY`
     * (where all failed) a compiled ability if any was requested, else the first
     * failing computed one — and surfaces the matching message: a computed
     * ability's own denial `Response`, or the rule-based diagnosis for compiled
     * abilities.
     *
     * @param array{all: array<int, string>, computed: array<int, string>, sql: array<int, string>} $split
     * @param array{sql: array<int, string>, computed: array<int, string>} $held
     */
    private function diagnoseNoTargetDenial(
        Authenticatable $user,
        array $split,
        array $held,
        AbilityMatchMode $matchMode,
        array $context,
    ): \Throwable {
        $failing = fn (string $ability): bool => in_array($ability, $split['computed'], true)
            ? !in_array($ability, $held['computed'], true)
            : !in_array($ability, $held['sql'], true);

        if ($matchMode === AbilityMatchMode::ALL) {
            $culprit = collect($split['all'])->first($failing);
        } else {
            /* Every ability failed; prefer a compiled ability so the rule-based
               diagnosis (forbidden vs ungranted) can speak. */
            $culprit = collect($split['all'])->first(fn (string $a): bool => in_array($a, $split['sql'], true))
                ?? collect($split['all'])->first($failing);
        }

        if ($culprit !== null && in_array($culprit, $split['computed'], true)) {
            $response = $this->evaluateComputedAbility($culprit, $user, $this->resolveEffectiveContext($context));

            return new WarrantAuthorizationException($response->message() ?? 'This action is unauthorized.');
        }

        return $this->diagnoseDenial($user, $split['sql'], null, $matchMode, $context)
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
        $target = $schema->resolveCheckTarget($target);

        if ($target === null) {
            return $schema->getAbilitiesWithoutTarget($user, context: $context);
        }

        static::assertSupportsTargetedChecks();

        /** @var Model $model */
        $model = new (static::model);
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        // selectUserAbilitiesInQuery adds the abilities list via selectSub aliased
        // AS abilities — NOT a real column on the underlying table. Using
        // ->value('abilities') here would call Laravel's first(['abilities']),
        // whose onceWithColumns mechanism replaces the SELECT clause with
        // ['abilities'], wiping the selectSub and yielding null. Read the
        // hydrated row instead so the alias survives.
        $row = (array)$schema->selectUserAbilitiesInQuery(
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
