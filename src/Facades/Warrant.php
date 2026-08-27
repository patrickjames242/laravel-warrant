<?php

namespace Warrant\Facades;

use Illuminate\Support\Facades\Facade;
use Warrant\WarrantManager;

/**
 * @method static \Warrant\SchemaRegistry registry()
 * @method static \Warrant\WarrantGuard|\Warrant\WarrantGuardForSchema guard(\Illuminate\Contracts\Auth\Authenticatable|\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string|null $schemaOrUser = null)
 * @method static bool can(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool canAny(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool cannot(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static void authorize(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static void authorizeAny(string|array $abilities, \Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array abilities(\Illuminate\Database\Eloquent\Model|string|array $target, array $context = [], \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static \Warrant\Reachability reachabilityOf(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string $ability, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool couldEverHave(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool couldEverHaveAny(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool alwaysHas(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool alwaysHasAny(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool neverHas(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static bool neverHasAny(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, string|array $abilities, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array possibleAbilities(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array guaranteedAbilities(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static array impossibleAbilities(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
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
