<?php

namespace Warden;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Warden\Reachability;
use Warden\Schema\WardenSchema;

trait HasWardenSchema
{
    /**
     * @return class-string<WardenSchema>
     */
    abstract public function wardenSchema(): string;

    public static function userHasAbilities(
        string|array $abilities,
        Model|string|null $target = null,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): bool
    {
        /** @var Model&self $model */
        $model = new static;
        $schemaClass = $model->wardenSchema();

        return $schemaClass::userHasAbilities($abilities, $target, $user, $matchMode, $context);
    }

    /**
     * Check whether the user has the given ability (or abilities) against this
     * model instance. Runs one targeted EXISTS query — avoid calling it per row
     * in a loop; use the selectAbilities/loadAbilities scopes for collections.
     *
     * @param  string|array<int, string>  $abilities
     */
    public function hasAbility(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): bool
    {
        return $this->wardenSchema()::userHasAbilities($abilities, $this, $user, $matchMode, $context);
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
        /** @var Model&self $model */
        $model = new static;
        $schemaClass = $model->wardenSchema();

        return $schemaClass::getUserAbilities($target, $user, $context);
    }

    /**
     * Classify one ability as NEVER / MAYBE / ALWAYS for the user, from the
     * structure of the rules alone (no conditions evaluated, no query run).
     */
    public static function abilityReachability(string $ability, ?Authenticatable $user = null): Reachability
    {
        return (new static)->wardenSchema()::abilityReachability($ability, $user);
    }

    /**
     * Whether the user could ever hold the ability (or abilities) under some
     * circumstance. See {@see WardenSchema::userCouldEverHave}.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userCouldEverHave(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->wardenSchema()::userCouldEverHave($abilities, $user, $matchMode);
    }

    /**
     * Whether the user is guaranteed the ability (or abilities) regardless of the
     * row. See {@see WardenSchema::userAlwaysHas}.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userAlwaysHas(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->wardenSchema()::userAlwaysHas($abilities, $user, $matchMode);
    }

    /**
     * Whether the user can never hold the ability (or abilities) under any
     * circumstance. See {@see WardenSchema::userNeverHas}.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userNeverHas(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->wardenSchema()::userNeverHas($abilities, $user, $matchMode);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserPossibleAbilities(?Authenticatable $user = null): array
    {
        return (new static)->wardenSchema()::getUserPossibleAbilities($user);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserGuaranteedAbilities(?Authenticatable $user = null): array
    {
        return (new static)->wardenSchema()::getUserGuaranteedAbilities($user);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserImpossibleAbilities(?Authenticatable $user = null): array
    {
        return (new static)->wardenSchema()::getUserImpossibleAbilities($user);
    }

    public function scopeHasAbility(
        EloquentBuilder $query,
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWardenSchemaInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeHasAbility requires an authenticated user or an explicit user instance.');
        }

        $schema->filterQuery(
            currentUser: $user,
            query: $query->getQuery(),
            targetSqlId: $model->getQualifiedKeyName(),
            abilities: $abilities,
            matchMode: $matchMode,
            context: $context,
        );

        return $query;
    }

    /**
     * @param  array<int, string>|null  $onlyAbilities  Compute only these per-row
     *   abilities instead of the full set (see selectAbilitiesInQuery).
     */
    public function scopeSelectAbilities(
        EloquentBuilder $query,
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        ?array $onlyAbilities = null,
        array $context = []
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWardenSchemaInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeSelectAbilities requires an authenticated user or an explicit user instance.');
        }

        $schema->selectAbilitiesInQuery(
            currentUser: $user,
            query: $query->getQuery(),
            targetSqlId: $model->getQualifiedKeyName(),
            selectedAbilitiesKey: $selectedAbilitiesKey,
            onlyAbilities: $onlyAbilities,
            context: $context,
        );

        return $query;
    }

    /**
     * @return array<int, string>
     */
    public function loadAbilities(
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        array $context = []
    ): array
    {
        $schemaClass = $this->wardenSchema();

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('loadAbilities requires an authenticated user or an explicit user instance.');
        }

        $abilities = $schemaClass::getUserAbilities($this, $user, $context);

        $this->setAttribute($selectedAbilitiesKey, $abilities);

        return $abilities;
    }

    protected function newWardenSchemaInstance(Model $model): WardenSchema
    {
        $schemaClass = $model->wardenSchema();

        if (!is_a($schemaClass, WardenSchema::class, true)) {
            throw new LogicException(
                sprintf('Model [%s] must return a WardenSchema class string, got [%s].', $model::class, $schemaClass)
            );
        }

        if ($schemaClass::model !== $model::class) {
            throw new LogicException(
                sprintf('Schema [%s] must manage model [%s], got [%s].', $schemaClass, $model::class, $schemaClass::model)
            );
        }

        return new $schemaClass;
    }
}
