<?php

namespace Warrant;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Warrant\Facades\Warrant;

class WarrantMiddleware
{
    /**
     * Build the route middleware string for an access control check.
     *
     * Example:
     * `WarrantMiddleware::string('course_sections', abilities: ['view', 'update'])`
     * returns `warrant:course_sections,view,update`.
     *
     * `WarrantMiddleware::string('course_sections', AbilityMatchMode::ANY, ['view', 'update'])`
     * returns `warrant:course_sections,any,view,update`.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function string(
        string $target,
        string|array $abilities,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): string {
        $normalizedAbilities = is_array($abilities) ? array_values($abilities) : [$abilities];
        $segments = ['warrant:'.self::normalizeTarget(
            $target,
        )];

        if ($matchMode !== AbilityMatchMode::ALL) {
            $segments[] = $matchMode->value;
        }

        return implode(',', [
            ...$segments,
            ...$normalizedAbilities,
        ]);
    }

    /**
     * Normalize a guard target to a schema key. Accepts a schema class, a model
     * class, or a bare schema key. A model resolves through its own
     * {@see \Warrant\HasWarrantSchema} trait, and a bare key is checked against the
     * schema index rather than passed through, so an unregistered target fails here
     * instead of at check time.
     */
    private static function normalizeTarget(
        string $target
    ): string {
        return Warrant::registry()->resolveSchemaKeyOrFail($target);
    }

    /**
     * The generic row/capability guard. One method, two modes: with no closure it
     * returns the middleware string; given a closure it wraps the grouped routes.
     *
     * Examples:
     * `WarrantMiddleware::guard('course_sections', 'view')` returns `warrant:course_sections,view`.
     * `WarrantMiddleware::guard('course_section', 'view', fn () => Route::get(...));` groups the routes.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function guard(
        string $target,
        string|array $abilities,
        ?Closure $routes = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): ?string {
        $middleware = self::string($target, $abilities, $matchMode);

        if (! $routes instanceof Closure) {
            return $middleware;
        }

        Route::middleware($middleware)->group($routes);

        return null;
    }

    /**
     * Guard a route by reachability: passes when the user *could ever* hold the
     * ability (or abilities). Target-free — the first argument is a schema key or
     * schema/model class, never a route parameter. One method, two modes: returns
     * the middleware string, or wraps grouped routes when given a closure.
     *
     * Examples:
     * `WarrantMiddleware::couldEver('timesheets', 'view')` returns `warrant.could-ever:timesheets,view`.
     * `WarrantMiddleware::couldEver('timesheets', 'view', fn () => Route::get(...));` groups the routes.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function couldEver(
        string $target,
        string|array $abilities,
        ?Closure $routes = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): ?string {
        return self::reachabilityHelper('warrant.could-ever', $target, $abilities, $routes, $matchMode);
    }

    /**
     * Guard a route by reachability: passes only when the user is *guaranteed* the
     * ability (or abilities) regardless of the row. See {@see couldEver} for the
     * two modes and the target rule.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function always(
        string $target,
        string|array $abilities,
        ?Closure $routes = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): ?string {
        return self::reachabilityHelper('warrant.always', $target, $abilities, $routes, $matchMode);
    }

    /**
     * Guard a route by reachability: passes only when the user can *never* hold the
     * ability (or abilities) under any circumstance — e.g. an upsell page shown to
     * users with no path to the feature. See {@see couldEver} for the two modes.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function never(
        string $target,
        string|array $abilities,
        ?Closure $routes = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL
    ): ?string {
        return self::reachabilityHelper('warrant.never', $target, $abilities, $routes, $matchMode);
    }

    /**
     * Build a reachability middleware string — `<alias>[.any]:<schemaKey>,<abilities>`
     * — or, when a closure is given, wrap the grouped routes in it. The mode and
     * match mode live entirely in the alias, so every token after the colon is an
     * ability and no ability name can collide with a keyword.
     *
     * @param  string|array<int, string>  $abilities
     */
    private static function reachabilityHelper(
        string $alias,
        string $target,
        string|array $abilities,
        ?Closure $routes,
        AbilityMatchMode $matchMode,
    ): ?string {
        if ($matchMode === AbilityMatchMode::ANY) {
            $alias .= '.any';
        }

        $normalizedAbilities = is_array($abilities) ? array_values($abilities) : [$abilities];

        $middleware = implode(',', [
            $alias.':'.self::normalizeTarget($target),
            ...$normalizedAbilities,
        ]);

        if (! $routes instanceof Closure) {
            return $middleware;
        }

        Route::middleware($middleware)->group($routes);

        return null;
    }

    /**
     * Guard `view` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canView('course_sections')`
     * returns `warrant:course_sections,view`.
     *
     * `WarrantMiddleware::canView('course_section', fn () => Route::get('/sections/{course_section}', ...));`
     * applies the targeted `view` middleware to the grouped route.
     *
     * `WarrantMiddleware::canView(CourseSectionSchema::class)`
     * returns `warrant:course_sections,view`.
     */
    public static function canView(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::VIEW, $routes);
    }

    /**
     * Guard `create` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canCreate('course_sections')`
     * returns `warrant:course_sections,create`.
     *
     * `WarrantMiddleware::canCreate('course_sections', fn () => Route::post('/sections', ...));`
     * applies the no-target `create` middleware to the grouped route.
     *
     * `WarrantMiddleware::canCreate(CourseSectionSchema::class)`
     * returns `warrant:course_sections,create`.
     */
    public static function canCreate(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::CREATE, $routes);
    }

    /**
     * Guard `update` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canUpdate('course_sections')`
     * returns `warrant:course_sections,update`.
     *
     * `WarrantMiddleware::canUpdate('course_section', fn () => Route::put('/sections/{course_section}', ...));`
     * applies the targeted `update` middleware to the grouped route.
     *
     * `WarrantMiddleware::canUpdate(CourseSectionSchema::class)`
     * returns `warrant:course_sections,update`.
     */
    public static function canUpdate(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::UPDATE, $routes);
    }

    /**
     * Guard `delete` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canDelete('course_sections')`
     * returns `warrant:course_sections,delete`.
     *
     * `WarrantMiddleware::canDelete('course_section', fn () => Route::delete('/sections/{course_section}', ...));`
     * applies the targeted `delete` middleware to the grouped route.
     *
     * `WarrantMiddleware::canDelete(CourseSectionSchema::class)`
     * returns `warrant:course_sections,delete`.
     */
    public static function canDelete(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::DELETE, $routes);
    }

    /**
     * Guard `archive` access for either a no-target schema key or a targeted route parameter.
     *
     * This helper has two modes:
     * - If no closure is provided, it returns the middleware string for manual route assignment.
     * - If a closure is provided, it wraps the routes in a middleware group for you.
     *
     * Examples:
     * `WarrantMiddleware::canArchive('course_sections')`
     * returns `warrant:course_sections,archive`.
     *
     * `WarrantMiddleware::canArchive('course_section', fn () => Route::post('/sections/{course_section}/archive', ...));`
     * applies the targeted `archive` middleware to the grouped route.
     *
     * `WarrantMiddleware::canArchive(CourseSectionSchema::class)`
     * returns `warrant:course_sections,archive`.
     */
    public static function canArchive(
        string $target,
        ?Closure $routes = null,
    ): ?string {
        return self::abilityHelper($target, StandardAbilities::ARCHIVE, $routes);
    }

    private static function abilityHelper(
        string $target,
        string $ability,
        ?Closure $routes = null,
    ): ?string {
        if ($routes instanceof Closure) {
            self::guard($target, $ability, $routes);

            return null;
        }

        return self::string($target, $ability);
    }

    public function handle(
        Request $request,
        Closure $next,
        string $target,
        string $matchModeOrFirstAbility,
        string ...$remainingAbilities
    ): Response {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            abort(403);
        }

        $abilityMatchMode = match ($matchModeOrFirstAbility) {
            'all' => AbilityMatchMode::ALL,
            'any' => AbilityMatchMode::ANY,
            default => AbilityMatchMode::ALL,
        };
        $abilities = in_array($matchModeOrFirstAbility, ['all', 'any'], true)
            ? $remainingAbilities
            : [$matchModeOrFirstAbility, ...$remainingAbilities];

        if ($abilities === []) {
            throw new InvalidArgumentException('Access control middleware requires at least one ability.');
        }

        $schemaClass = Warrant::registry()->resolveSchemaClassOrNull($target);
        $resolvedTarget = null;

        if ($schemaClass === null) {
            $resolvedTarget = $request->route($target);

            if (! $resolvedTarget instanceof Model) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Access control middleware route parameter [%s] must resolve to a model instance.',
                        $target
                    )
                );
            }

            $schemaClass = Warrant::registry()->resolveSchemaClassOrNull($resolvedTarget::class);
        }

        if ($schemaClass === null) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to resolve access control schema for [%s].',
                    $target
                )
            );
        }

        // Throws on denial — a message-bearing `cannot` rule surfaces its message
        // (or custom exception); otherwise a generic 403.
        $guard = Warrant::forSchema($schemaClass, $user);

        if ($abilityMatchMode === AbilityMatchMode::ANY) {
            $guard->authorizeAny($abilities, $resolvedTarget);
        } else {
            $guard->authorize($abilities, $resolvedTarget);
        }

        return $next($request);
    }
}
