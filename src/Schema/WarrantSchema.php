<?php

namespace Warrant\Schema;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\ConditionResolver;
use Warrant\Facades\Warrant;
use Warrant\Guard\WarrantGuardForSchema;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Concerns\ReflectsSchemaDefinition;
use Warrant\Schema\Concerns\ResolvesConditions;
use Warrant\Schema\Concerns\ResolvesContext;
use Warrant\Schema\Conditions\RowConditionContext;

/**
 * A Warrant schema declares the vocabulary a rule string may reference for one
 * entity: its abilities (`#[Ability]` constants) and its conditions
 * (`#[RowCondition]` / `#[GlobalCondition]` methods, which emit SQL). It is NOT where the
 * rules live — those come from the {@see \Warrant\Rules\RuleResolver} as a
 * {@see \Warrant\Rules\WarrantRuleSet}, compiled against this schema.
 *
 * The schema is pure definition: it holds no user and performs no authorization.
 * Every user-scoped operation — checks, query filtering, ability listing, denial
 * diagnosis, reachability — lives on {@see \Warrant\Guard\WarrantGuardForSchema}, which
 * is constructed with a schema instance plus a user and reads the definition from
 * here. Reach a guard through the {@see \Warrant\Facades\Warrant} facade
 * (`Warrant::forSchema(...)`).
 *
 * The definition is split across concerns:
 *  - {@see ReflectsSchemaDefinition} — discovering abilities/conditions via reflection;
 *  - {@see ResolvesConditions}       — the ConditionResolver seam + ability validation;
 *  - {@see ResolvesContext}          — merging/enforcing the schema's context policy.
 *
 * This class itself carries the configuration constants, the instance lifecycle,
 * and the author-facing override hooks the engine consults.
 */
abstract class WarrantSchema implements ConditionResolver
{
    use ReflectsSchemaDefinition;
    use ResolvesConditions;
    use ResolvesContext;

    /**
     * @var class-string<Model>
     */
    public const model = '';

    /**
     * The column a {@see virtualTable}'s rows are identified by.
     *
     * A model answers this itself, through `getKeyName()`, so this is for a
     * virtual table — whose rows are a query's output and have no key of their
     * own. Declaring it is what lets such a schema be addressed without a
     * `matchKey()`: the built-in key compares against this column, and a
     * condition may write `$c->row()` with no argument.
     *
     * ```php
     * public const key = 'team_id';
     * ```
     *
     * Leave it unset for a virtual table with no identifying column. Such a
     * schema is still filtered and still carries per-row ability columns — both
     * correlate against rows the outer query already produced — but it cannot be
     * asked about one row, and a targeted check against it fails.
     *
     * Declaring it alongside a {@see model} is rejected: the model's own key
     * already answers, and two declarations could disagree.
     */
    public const key = '';

    /**
     * The {@see ConditionResolver} view of {@see model}, so the compiler can tell
     * whether this schema has rows at all without being handed the answer.
     *
     * @return class-string<Model>|''
     */
    public static function modelClass(): string
    {
        return static::model;
    }

    /**
     * @var array<string, true>
     */
    private array $abilityLookup;

    public function __construct()
    {
        $this->abilityLookup = array_fill_keys(static::abilityNames(), true);
    }

    /**
     * The schema-bound engine for this schema and a user (defaults to the current
     * user). Sugar for `Warrant::forSchema(static::class, $user)`, e.g.
     * `PostSchema::guard($user)->can('publish', $post)`.
     */
    public static function guard(?Authenticatable $user = null): WarrantGuardForSchema
    {
        return Warrant::forSchema(static::class, $user);
    }

    /**
     * The query this schema's rows come from, when they are not simply a model's
     * table — a database view defined in the schema instead of in DDL.
     *
     * Null, the default, means the rows are {@see model}'s table.
     *
     * ```php
     * public static function virtualTable(): ?QueryBuilder
     * {
     *     return DB::table('teams')
     *         ->crossJoin('calendar_days')
     *         ->select(['teams.id as team_id', 'calendar_days.day']);
     * }
     * ```
     *
     * It takes no user and no check-time context, deliberately. A virtual table
     * says what its rows *are*; who may touch them is what the rules are for.
     * Filtering by the current user here would move an access decision out of the
     * rule language, where neither the rule text nor reachability analysis can
     * see it.
     *
     * Called afresh wherever the query is needed rather than memoized, so nothing
     * downstream can mutate a shared builder.
     */
    public static function virtualTable(): ?QueryBuilder
    {
        return null;
    }

    /**
     * Whether this schema has rows at all, and so whether it answers targeted
     * checks — from a model's table, or from a {@see virtualTable}. A schema with
     * neither is a capability schema: it declares abilities about the user and
     * nothing else.
     */
    public static function hasRows(): bool
    {
        return static::model !== '' || static::declaresVirtualTable();
    }

    /**
     * Whether this schema overrides {@see virtualTable}.
     *
     * Read by reflection rather than by calling the method, so asking whether a
     * schema has rows never builds a query — and so the override stays the single
     * declaration, with no second flag to contradict it.
     *
     * @var array<class-string<self>, bool>
     */
    private static array $declaresVirtualTable = [];

    private static function declaresVirtualTable(): bool
    {
        return self::$declaresVirtualTable[static::class] ??= (new \ReflectionMethod(static::class, 'virtualTable'))
            ->getDeclaringClass()
            ->getName() !== self::class;
    }

    /*
     * `matchKey()` — how this schema's rows are addressed.
     *
     * Deliberately *not* declared here. PHP forbids an override from adding
     * required parameters, so a concrete method on this class would make a key of
     * several parts impossible to write:
     *
     *     public function matchKey(RowConditionContext $c, mixed $teamId, mixed $day): ?Builder
     *     {
     *         return $c->query
     *             ->where($c->row('team_id'), '=', $teamId)
     *             ->where($c->row('day'), '=', $day);
     *     }
     *
     * Declare it on the schema when its rows are addressed by something other than
     * a primary key. Omit it and the engine addresses them by their key, which is
     * what the single argument of `documents(@context id)` means — see
     * {@see \Warrant\Schema\Concerns\ResolvesConditions::defaultMatchKey()} for
     * that default, and {@see \Warrant\Schema\Concerns\ReflectsSchemaDefinition::keyDefinition()}
     * for how the declaration is read.
     */

    /**
     * Rules that are always in force for this schema, regardless of what the
     * resolver returns. They are merged into every resolved rule set before
     * compilation, so they are validated and compiled exactly like resolver
     * rules (deny-overrides still applies across both).
     *
     * Override to establish baseline access — e.g. a super-admin escape hatch or
     * a universal deny. Return either a plain list of {@see WarrantRule} or a
     * fully-formed {@see WarrantRuleSet} for this schema:
     *
     * ```php
     * public function implicitRules(): array|WarrantRuleSet
     * {
     *     return [
     *         WarrantRule::fromSyntax('if is_super_admin they can *'),
     *         WarrantRule::fromSyntax('if is_suspended they cannot *'),
     *     ];
     * }
     * ```
     *
     * @return array<int, WarrantRule>|WarrantRuleSet
     */
    public function implicitRules(): array|WarrantRuleSet
    {
        return [];
    }

    /**
     * Default check-time context for this schema, merged *under* any context
     * passed explicitly to a check (explicit values win; partial explicit context
     * is allowed). Override to source the frame from the request/tenant/container
     * so that param-less entry points — route middleware and the query scopes —
     * receive context without a `context:` argument:
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

    /**
     * The schema-level fallback message for a *forbidden* denial — a matching
     * `cannot` rule blocked the check but carried no {@see \Warrant\Rules\WarrantRule::$message}
     * of its own. Consulted after a rule's own message and before the generic 403,
     * so it catches every message-less `cannot`.
     *
     * The {@see WarrantDenialContext} carries the responsible `rule` and the gate
     * abilities it blocked. Return a string (wrapped in a
     * {@see \Warrant\WarrantAuthorizationException} → 403), a `Throwable` (thrown as-is), or
     * null to fall through (to {@see ungrantedDenialMessage} if some ability was
     * also ungranted, otherwise the generic 403).
     */
    public function forbiddenDenialMessage(WarrantDenialContext $context): string|\Throwable|null
    {
        return null;
    }

    /**
     * The message for a denial caused by the *absence of a grant* — the user was
     * neither forbidden by a `cannot` nor allowed by a `can`. This is distinct
     * from being forbidden: a `cannot` that blocks the check is handled by a
     * rule's own message or {@see forbiddenDenialMessage}, never here.
     *
     * Return a string (wrapped in a {@see \Warrant\WarrantAuthorizationException} → 403), a
     * `Throwable` (thrown as-is), or null to keep the generic default. The
     * {@see WarrantUngrantedContext} carries the gate and the ungranted abilities,
     * so the message can speak to the whole request (e.g. "you need at least one
     * of …" under `ANY`).
     */
    public function ungrantedDenialMessage(WarrantUngrantedContext $context): string|\Throwable|null
    {
        return null;
    }
}
