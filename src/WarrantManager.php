<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\Schema\WarrantSchema;

/**
 * Entry point for Warrant. Holds the {@see SchemaRegistry} (reached through
 * {@see registry()}) and exposes the ability/reachability helpers that resolve a
 * schema through the registry and delegate to it.
 *
 * Bound as a singleton and reached through the Warrant facade.
 */
class WarrantManager
{
    public function __construct(private readonly SchemaRegistry $registry)
    {
    }

    /**
     * The schema registry: model/schema-key/schema-class maps and their resolvers.
     */
    public function registry(): SchemaRegistry
    {
        return $this->registry;
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
            ->map(fn (string $schemaClassOrSchemaKey): string => $this->registry->resolveSchemaClassOrFail(
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
        return $this->registry->resolveSchemaClassOrFail($schemaClassOrKey)::abilityReachability($ability, $user);
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
        return $this->registry->resolveSchemaClassOrFail($schemaClassOrKey)::userCouldEverHave($abilities, $user, $matchMode);
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
        return $this->registry->resolveSchemaClassOrFail($schemaClassOrKey)::userAlwaysHas($abilities, $user, $matchMode);
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
        return $this->registry->resolveSchemaClassOrFail($schemaClassOrKey)::userNeverHas($abilities, $user, $matchMode);
    }
}
