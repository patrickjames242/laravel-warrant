<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\Schema\WarrantSchema;

/**
 * Central registry and validation entry point for Warrant.
 *
 * Responsible for:
 * - mapping model classes to schema classes
 * - mapping schema keys to schema classes
 * - validating persisted rule sets against registered schemas
 *
 * Bound as a singleton and reached through the Warrant facade. The registry is
 * built from the `warrant.schemas` config.
 */
class WarrantManager
{
    /**
     * @var array<class-string<Model>, class-string<WarrantSchema>>
     */
    private array $modelsToSchemas = [];

    /**
     * @var array<string, class-string<WarrantSchema>>
     */
    private array $schemaKeysToSchemas = [];

    /**
     * @param  array<int, class-string<WarrantSchema>>  $schemaClasses
     */
    public function __construct(array $schemaClasses)
    {
        foreach ($schemaClasses as $schemaClass) {
            $model = $schemaClass::model;

            $schemaKey = $schemaClass::schemaKey();

            if (isset($this->schemaKeysToSchemas[$schemaKey])) {
                throw new InvalidArgumentException('Duplicate schema for schema key '.$schemaKey);
            }

            /* Capability schemas have no model; only model-backed schemas are
               indexed by model class. */
            if ($model !== '') {
                if (isset($this->modelsToSchemas[$model])) {
                    throw new InvalidArgumentException('Duplicate schema for model '.$model);
                }

                $this->modelsToSchemas[$model] = $schemaClass;
            }

            $this->schemaKeysToSchemas[$schemaKey] = $schemaClass;
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return class-string<WarrantSchema>
     */
    public function getSchemaForModelClass(string $modelClass): string
    {
        if (!isset($this->modelsToSchemas[$modelClass])) {
            throw new OutOfBoundsException(sprintf('No Warrant schema registered for model [%s].', $modelClass));
        }

        return $this->modelsToSchemas[$modelClass];
    }

    /**
     * @return class-string<WarrantSchema>
     */
    public function getSchemaForKey(string $schemaKey): string
    {
        if (!isset($this->schemaKeysToSchemas[$schemaKey])) {
            throw new OutOfBoundsException(sprintf('No Warrant schema registered for schema key [%s].', $schemaKey));
        }

        return $this->schemaKeysToSchemas[$schemaKey];
    }

    /**
     * Normalize any of the accepted schema references to a schema key.
     *
     * Accepts a schema key string, a {@see WarrantSchema} instance or class-string,
     * or a {@see Model} instance or class-string. A plain string that matches
     * neither a schema class nor a model class is treated as a literal schema key.
     */
    public function resolveSchemaKey(Model|WarrantSchema|string $schema): string
    {
        if ($schema instanceof WarrantSchema) {
            return $schema::schemaKey();
        }

        if ($schema instanceof Model) {
            return $this->getSchemaForModelClass($schema::class)::schemaKey();
        }

        if (is_a($schema, WarrantSchema::class, true)) {
            return $schema::schemaKey();
        }

        if (is_a($schema, Model::class, true)) {
            return $this->getSchemaForModelClass($schema)::schemaKey();
        }

        return $schema;
    }

    /**
     * Combined no-target ability bag for multiple schemas. Each argument may be
     * a WarrantSchema class string or a schema key.
     *
     * @return array<string, array{schema_key: string, abilities: array<int, string>, target: null}>
     */
    public function getNoTargetAbilitiesBag(
        ?Authenticatable $user = null,
        string ...$schemaClassesOrSchemaKeys
    ): array
    {
        return collect($schemaClassesOrSchemaKeys)
            ->map(fn (string $schemaClassOrSchemaKey): string => $this->resolveSchemaClass(
                $schemaClassOrSchemaKey
            ))
            ->reduce(
                fn (array $combinedBag, string $schemaClass): array => [
                    ...$combinedBag,
                    $schemaClass::schemaKey() => $schemaClass::getNoTargetAbilitiesBag($user),
                ],
                []
            );
    }

    /**
     * Classify one ability's reachability for a schema (by class or schema key).
     */
    public function abilityReachability(
        string $schemaClassOrKey,
        string $ability,
        ?Authenticatable $user = null,
    ): \Warrant\Reachability {
        return $this->resolveSchemaClass($schemaClassOrKey)::abilityReachability($ability, $user);
    }

    /**
     * Whether the user could ever hold the ability (or abilities) on a schema.
     *
     * @param string|array<int, string> $abilities
     */
    public function userCouldEverHave(
        string $schemaClassOrKey,
        string|array $abilities,
        ?Authenticatable $user = null,
        \Warrant\AbilityMatchMode $matchMode = \Warrant\AbilityMatchMode::ALL,
    ): bool {
        return $this->resolveSchemaClass($schemaClassOrKey)::userCouldEverHave($abilities, $user, $matchMode);
    }

    /**
     * Whether the user is guaranteed the ability (or abilities) on a schema.
     *
     * @param string|array<int, string> $abilities
     */
    public function userAlwaysHas(
        string $schemaClassOrKey,
        string|array $abilities,
        ?Authenticatable $user = null,
        \Warrant\AbilityMatchMode $matchMode = \Warrant\AbilityMatchMode::ALL,
    ): bool {
        return $this->resolveSchemaClass($schemaClassOrKey)::userAlwaysHas($abilities, $user, $matchMode);
    }

    /**
     * Whether the user can never hold the ability (or abilities) on a schema.
     *
     * @param string|array<int, string> $abilities
     */
    public function userNeverHas(
        string $schemaClassOrKey,
        string|array $abilities,
        ?Authenticatable $user = null,
        \Warrant\AbilityMatchMode $matchMode = \Warrant\AbilityMatchMode::ALL,
    ): bool {
        return $this->resolveSchemaClass($schemaClassOrKey)::userNeverHas($abilities, $user, $matchMode);
    }

    /**
     * The schema classes registered with Warrant.
     *
     * @return array<int, class-string<WarrantSchema>>
     */
    public function registeredSchemas(): array
    {
        return array_values($this->schemaKeysToSchemas);
    }

    /**
     * @return class-string<WarrantSchema>
     */
    private function resolveSchemaClass(string $schemaClassOrSchemaKey): string
    {
        if (is_a($schemaClassOrSchemaKey, WarrantSchema::class, true)) {
            return $schemaClassOrSchemaKey;
        }

        return $this->getSchemaForKey($schemaClassOrSchemaKey);
    }
}
