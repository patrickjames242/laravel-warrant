<?php

namespace Warden\Facades;

use Illuminate\Support\Facades\Facade;
use Warden\WardenManager;

/**
 * @method static string getSchemaForModelClass(string $modelClass)
 * @method static string getSchemaForKey(string $schemaKey)
 * @method static string resolveSchemaKey(\Illuminate\Database\Eloquent\Model|\Warden\Schema\WardenSchema|string $schema)
 * @method static array getNoTargetAbilitiesBag(\Illuminate\Contracts\Auth\Authenticatable|null $user = null, string ...$schemaClassesOrSchemaKeys)
 * @method static \Warden\Reachability abilityReachability(string $schemaClassOrKey, string $ability, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool userCouldEverHave(string $schemaClassOrKey, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null, \Warden\AbilityMatchMode $matchMode = \Warden\AbilityMatchMode::ALL)
 * @method static bool userAlwaysHas(string $schemaClassOrKey, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null, \Warden\AbilityMatchMode $matchMode = \Warden\AbilityMatchMode::ALL)
 * @method static bool userNeverHas(string $schemaClassOrKey, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null, \Warden\AbilityMatchMode $matchMode = \Warden\AbilityMatchMode::ALL)
 * @method static array registeredSchemas()
 *
 * @see \Warden\WardenManager
 */
class Warden extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WardenManager::class;
    }
}
