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
            targetSqlId: $this->getQualifiedKeyName(),
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
            targetSqlId: $this->getQualifiedKeyName(),
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
