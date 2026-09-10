<?php

namespace Warrant\Schema;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
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
     * Narrow a query to the row a handle's arguments name.
     *
     * This is how a row is addressed. Every row-bound reference goes through it —
     * a `can(... for <schema>(<args>))` or `check(... for <schema>(<args>))` hop,
     * and a targeted check from PHP — with the arguments the caller supplied
     * bound positionally after the context, exactly as a condition's are.
     *
     * The default addresses a row by its primary key, which is what the single
     * argument of `documents(@context id)` means. Override it to address rows by
     * something else — a natural key, or several columns where no single one is
     * unique:
     *
     * ```php
     * public function matchKey(RowConditionContext $c, mixed $tenant, mixed $slug): ?Builder
     * {
     *     return $c->query
     *         ->where($c->row('tenant_slug'), '=', $tenant)
     *         ->where($c->row('slug'), '=', $slug);
     * }
     * ```
     *
     * The override's parameters decide how many arguments a handle must supply,
     * and validation reports a mismatch against the rule text. Type them loosely:
     * a `@column` or `@sql` argument arrives as an
     * {@see \Illuminate\Database\Query\Expression}, not a scalar, so a
     * `string` parameter would reject the very references a hop correlates with.
     *
     * **A key must identify at most one row.** Nothing enforces it, and a key
     * that matches several turns an `exists` from "this row grants it" into "some
     * row grants it".
     *
     * Returning null answers unknown, which neither grants nor lifts a deny — the
     * safe answer for arguments that name no row. That is why the default returns
     * null for a null key: an absent `@context` value, or a model with no key
     * yet, names nothing, and `exists` cannot report unknown once the subquery is
     * built.
     */
    public function matchKey(RowConditionContext $c, mixed $key): ?Builder
    {
        return $key === null ? null : $c->query->where($c->row(), '=', $key);
    }

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
