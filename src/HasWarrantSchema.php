<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Warrant\Reachability;
use Warrant\Schema\WarrantSchema;

trait HasWarrantSchema
{
    /**
     * @return class-string<WarrantSchema>
     */
    abstract public function warrantSchema(): string;

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
        $schemaClass = $model->warrantSchema();

        return $schemaClass::userHasAbilities($abilities, $target, $user, $matchMode, $context);
    }

    /**
     * The throwing sibling of {@see userHasAbilities}: assert the user holds the
     * abilities, or throw a {@see WarrantAuthorizationException} (403) carrying the
     * responsible rule's denial message. Same arguments as userHasAbilities.
     *
     * @param  string|array<int, string>  $abilities
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
        /** @var Model&self $model */
        $model = new static;
        $schemaClass = $model->warrantSchema();

        $schemaClass::authorize($abilities, $target, $user, $matchMode, $context);
    }

    /**
     * Check whether the user has the given ability (or abilities) against this
     * model instance. Runs one targeted EXISTS query — avoid calling it per row
     * in a loop; use the selectUserAbilities/loadUserAbilities scopes for collections.
     *
     * @param  string|array<int, string>  $abilities
     */
    public function userHasAbility(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): bool
    {
        return $this->warrantSchema()::userHasAbilities($abilities, $this, $user, $matchMode, $context);
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
        $schemaClass = $model->warrantSchema();

        return $schemaClass::getUserAbilities($target, $user, $context);
    }

    /**
     * Classify one ability as NEVER / MAYBE / ALWAYS for the user, from the
     * structure of the rules alone (no conditions evaluated, no query run).
     */
    public static function abilityReachability(string $ability, ?Authenticatable $user = null): Reachability
    {
        return (new static)->warrantSchema()::abilityReachability($ability, $user);
    }

    /**
     * Whether the user could ever hold the ability (or abilities) under some
     * circumstance. See {@see WarrantSchema::userCouldEverHave}.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userCouldEverHave(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->warrantSchema()::userCouldEverHave($abilities, $user, $matchMode);
    }

    /**
     * Whether the user is guaranteed the ability (or abilities) regardless of the
     * row. See {@see WarrantSchema::userAlwaysHas}.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userAlwaysHas(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->warrantSchema()::userAlwaysHas($abilities, $user, $matchMode);
    }

    /**
     * Whether the user can never hold the ability (or abilities) under any
     * circumstance. See {@see WarrantSchema::userNeverHas}.
     *
     * @param string|array<int, string> $abilities
     */
    public static function userNeverHas(
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ): bool {
        return (new static)->warrantSchema()::userNeverHas($abilities, $user, $matchMode);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserPossibleAbilities(?Authenticatable $user = null): array
    {
        return (new static)->warrantSchema()::getUserPossibleAbilities($user);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserGuaranteedAbilities(?Authenticatable $user = null): array
    {
        return (new static)->warrantSchema()::getUserGuaranteedAbilities($user);
    }

    /**
     * @return array<int, string>
     */
    public static function getUserImpossibleAbilities(?Authenticatable $user = null): array
    {
        return (new static)->warrantSchema()::getUserImpossibleAbilities($user);
    }

    public function scopeUserHasAbility(
        EloquentBuilder $query,
        string|array $abilities,
        ?Authenticatable $user = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWarrantSchemaInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeUserHasAbility requires an authenticated user or an explicit user instance.');
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
     *   abilities instead of the full set (see selectUserAbilitiesInQuery).
     */
    public function scopeSelectUserAbilities(
        EloquentBuilder $query,
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        ?array $onlyAbilities = null,
        array $context = []
    ): EloquentBuilder
    {
        $model = $query->getModel();
        $schema = $this->newWarrantSchemaInstance($model);

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('scopeSelectUserAbilities requires an authenticated user or an explicit user instance.');
        }

        $schema->selectUserAbilitiesInQuery(
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
    public function loadUserAbilities(
        ?Authenticatable $user = null,
        string $selectedAbilitiesKey = 'abilities',
        array $context = []
    ): array
    {
        $schemaClass = $this->warrantSchema();

        $user ??= auth()->user();

        if (!$user instanceof Authenticatable) {
            throw new LogicException('loadUserAbilities requires an authenticated user or an explicit user instance.');
        }

        $abilities = $schemaClass::getUserAbilities($this, $user, $context);

        $this->setAttribute($selectedAbilitiesKey, $abilities);

        return $abilities;
    }

    protected function newWarrantSchemaInstance(Model $model): WarrantSchema
    {
        $schemaClass = $model->warrantSchema();

        if (!is_a($schemaClass, WarrantSchema::class, true)) {
            throw new LogicException(
                sprintf('Model [%s] must return a WarrantSchema class string, got [%s].', $model::class, $schemaClass)
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
