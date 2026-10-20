<?php

namespace Warrant\Facades;

use Illuminate\Support\Facades\Facade;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\WarrantManager;

/**
 * @method static \Warrant\Registry\SchemaRegistry registry()
 * @method static \Warrant\Guard\WarrantGuard guard(\Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static void flush(\Illuminate\Contracts\Auth\Authenticatable|null $user = null)
 * @method static \Warrant\Guard\WarrantGuardForSchema forSchema(\Illuminate\Database\Eloquent\Model|\Warrant\Schema\WarrantSchema|string $schema, \Illuminate\Contracts\Auth\Authenticatable|null $user = null)
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

    /*
    |--------------------------------------------------------------------------
    | Rule authoring
    |--------------------------------------------------------------------------
    |
    | Rule text of every form is read by one call, parse(), which answers with a
    | WarrantSyntax tree whose children say what the text held: a condition, a
    | rule, unscoped rules, or one or more `for <schema>` bodies. Nothing about
    | the text has to be known before it is read.
    |
    | A schema named in the text's own `for` header travels with the text, and
    | tooling reading the source — the language server, an editor extension — can
    | see it. Text with no header is given its schema by the caller, with
    | ValidSyntax::scopedTo().
    |
    | Rules, conditions and rule sets are built in PHP through
    | \Warrant\Builders\Build.
    |
    | These are real statics rather than proxied @method entries: they need no user,
    | no state and nothing from the container, and WarrantManager is otherwise
    | entirely user-scoped checking and reachability.
    |
    */

    /**
     * Parse Warrant rule text of any form.
     *
     *     Warrant::parse('is_owner or is_admin')->conditionExpression()
     *     Warrant::parse('if is_self they can view')->rule()
     *     Warrant::parse('for documents { if is_self they can view }')->ruleSet()
     *     Warrant::parse('if is_self they can view')->scopedTo('documents')
     *     Warrant::parse(new BoundSyntax('if in_team(:team) they can view', ['team' => $team]))->rule()
     */
    public static function parse(string|BoundSyntax $syntax): WarrantSyntax
    {
        return WarrantSyntax::parse($syntax);
    }

    /**
     * Parse the Warrant rule text in a file. The `.warrant` extension may be left
     * off: `Warrant::parseFile(resource_path('warrant/timesheets'))` reads
     * `timesheets.warrant`.
     *
     * @param array<string, mixed> $bindings Values for `:name` placeholders, by name.
     */
    public static function parseFile(string $path, array $bindings = []): WarrantSyntax
    {
        return WarrantSyntax::parseFile($path, $bindings);
    }
}
