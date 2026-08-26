<?php

namespace Warrant\Facades;

use Illuminate\Support\Facades\Facade;
use Warrant\WarrantManager;

/**
 * @method static \Warrant\SchemaRegistry registry()
 * @method static array getNoTargetAbilitiesBag(\Illuminate\Contracts\Auth\Authenticatable|null $user = null, string ...$schemaClassesOrSchemaKeys)
 * @method static \Warrant\Reachability abilityReachability(string $schemaClassOrKey, string $ability, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool userCouldEverHave(string $schemaClassOrKey, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null, \Warrant\AbilityMatchMode $matchMode = \Warrant\AbilityMatchMode::ALL)
 * @method static bool userAlwaysHas(string $schemaClassOrKey, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null, \Warrant\AbilityMatchMode $matchMode = \Warrant\AbilityMatchMode::ALL)
 * @method static bool userNeverHas(string $schemaClassOrKey, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null, \Warrant\AbilityMatchMode $matchMode = \Warrant\AbilityMatchMode::ALL)
 *
 * @see \Warrant\WarrantManager
 */
class Warrant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WarrantManager::class;
    }
}
