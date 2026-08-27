<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use LogicException;
use Warrant\Facades\Warrant;
use Warrant\Schema\WarrantSchema;

/**
 * Attaches a model to its {@see WarrantSchema} and exposes the query-time
 * conveniences that belong on the model: two access-control scopes and an
 * attribute loader. Every user-scoped check now lives on the Warrant guard —
 * reach it with `Warrant::guard($user)->forSchema($model)` (or via the facade's
 * check helpers) — so it is deliberately absent here.
 */
trait HasWarrantSchema
{
    /**
     * @return class-string<WarrantSchema>
     */
    abstract public function warrantSchema(): string;

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
        Warrant::guard($user)->forSchema($this->validatedWarrantSchema())->filterQuery(
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
        Warrant::guard($user)->forSchema($this->validatedWarrantSchema())->selectAbilitiesInQuery(
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
        $abilities = Warrant::guard($user)->forSchema($this->validatedWarrantSchema())->abilities($this, $context);

        $this->setAttribute($selectedAbilitiesKey, $abilities);

        return $abilities;
    }

    /**
     * The model's {@see WarrantSchema} class, validated to actually manage this
     * model. Returned as a class-string for the guard to resolve.
     *
     * @return class-string<WarrantSchema>
     */
    protected function validatedWarrantSchema(): string
    {
        $schemaClass = $this->warrantSchema();

        if (! is_a($schemaClass, WarrantSchema::class, true)) {
            throw new LogicException(
                sprintf('Model [%s] must return a WarrantSchema class string, got [%s].', static::class, $schemaClass)
            );
        }

        if ($schemaClass::model !== static::class) {
            throw new LogicException(
                sprintf('Schema [%s] must manage model [%s], got [%s].', $schemaClass, static::class, $schemaClass::model)
            );
        }

        return $schemaClass;
    }
}
