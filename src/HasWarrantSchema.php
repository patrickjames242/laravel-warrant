<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Warrant\Facades\Warrant;
use Warrant\Schema\WarrantSchema;

/**
 * Attaches a model to its {@see WarrantSchema} and exposes the query-time
 * conveniences that belong on the model: two access-control scopes and an
 * attribute loader. Every user-scoped check now lives on the Warrant guard —
 * reach it with `Warrant::forSchema($model, $user)` (or via the facade's
 * check helpers) — so it is deliberately absent here.
 */
trait HasWarrantSchema
{
    /**
     * The {@see WarrantSchema} class that governs this model.
     *
     * Static because this is the authoritative model->schema direction: Warrant
     * resolves it from a bare model class-string (a no-target check, a Gate call
     * like `can('create', Post::class)`, or the registry's cross-reference check),
     * and an instance method would force a model to be constructed — and booted —
     * just to answer it.
     *
     * @return class-string<WarrantSchema>
     */
    abstract public static function warrantSchema(): string;

    /**
     * The alias the rule being compiled is using for this model's rows, or null
     * outside a rule.
     *
     * Declared rather than dynamic so it stays a real property: an undeclared one
     * would fall to Eloquent's __set and land in $attributes, where a column of
     * the same name would collide with it.
     *
     * @internal Set by Warrant while a row condition runs.
     */
    private ?string $warrantCurrentRuleAlias = null;

    /**
     * @internal
     */
    public function setWarrantCurrentRuleAlias(?string $alias): void
    {
        $this->warrantCurrentRuleAlias = $alias;
    }

    /**
     * Qualify one of this model's columns with the name its rows answer to.
     *
     * Ordinarily that is the table, exactly as {@see qualifyColumn} would give
     * it. Inside a Warrant row condition it is whatever the compiler has named
     * the row — a host query's alias, or a cross-schema hop's — so a scope built
     * on this follows the row instead of writing a table the query never
     * mentions:
     *
     *     ->whereColumn('payroll_users.timesheet_id', $this->warrantQualifyColumn('id'))
     *
     * A scope is free to keep using qualifyColumn(); it simply names the table
     * always, and so cannot be reached through an alias. Warrant does not
     * override qualifyColumn() to do this, because a trait method beats an
     * inherited one — a base model's own override would be silently replaced.
     *
     * Omit $column for the model's key. A column that already carries a table
     * prefix is returned untouched, matching qualifyColumn().
     *
     * Also reachable from the builder — `$query->warrantQualifyColumn('id')` — so
     * a scope may use whichever of the two it has to hand.
     */
    public function warrantQualifyColumn(?string $column = null): string
    {
        $column ??= $this->getKeyName();

        if (str_contains($column, '.')) {
            return $column;
        }

        return ($this->warrantCurrentRuleAlias ?? $this->getTable()).'.'.$column;
    }

    /*
     * The helpers below hand the guard `static::class` — the model — rather than
     * the schema, so the registry resolves the pair from the model end and
     * cross-checks it in that direction. That is what catches a subclass which
     * inherited warrantSchema() from its parent: the parent's schema names the
     * parent, not the subclass. Passing the schema instead would check the pair
     * from the schema end, where that mismatch is invisible.
     */

    /**
     * Scope the query to rows the user can act on with the given ability(ies).
     *
     * @param  string|array<int, string>  $abilities
     */
    public function scopeUserHasAbility(
        EloquentBuilder $query,
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): EloquentBuilder
    {
        Warrant::forSchema(static::class, $user)->filterQuery(
            query: $query->getQuery(),
            abilities: $abilities,
            matchMode: $matchMode,
            context: $context,
        );

        return $query;
    }

    /**
     * @param  array<int, string>|null  $onlyAbilities  Compute only these per-row
     *   abilities instead of the full set (see WarrantGuardForSchema::selectAbilitiesInQuery).
     */
    public function scopeSelectUserAbilities(
        EloquentBuilder $query,
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        ?array $onlyAbilities = null,
        array $context = []
    ): EloquentBuilder
    {
        Warrant::forSchema(static::class, $user)->selectAbilitiesInQuery(
            query: $query->getQuery(),
            selectedAbilitiesKey: $selectedAbilitiesKey,
            onlyAbilities: $onlyAbilities,
            context: $context,
        );

        return $query;
    }

    /**
     * Compute the user's abilities for this row and set them as an attribute.
     *
     * @return array<int, string>
     */
    public function loadUserAbilities(
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        array $context = []
    ): array
    {
        $abilities = Warrant::forSchema(static::class, $user)->abilities($this, $context);

        $this->setAttribute($selectedAbilitiesKey, $abilities);

        return $abilities;
    }
}
