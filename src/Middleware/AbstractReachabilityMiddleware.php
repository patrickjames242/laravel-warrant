<?php

namespace Warden\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use OutOfBoundsException;
use Symfony\Component\HttpFoundation\Response;
use Warden\AbilityMatchMode;
use Warden\Facades\Warden;
use Warden\Reachability;

/**
 * Base for the structural reachability route guards
 * (`warden.could-ever`, `warden.always`, `warden.never`, each with a `.any`
 * variant). Each concrete subclass fixes two things — which reachability
 * outcomes pass, and the match mode across multiple abilities — so the alias
 * carries them and the route string after the colon is *only* the schema key
 * and abilities. Because the mode and match mode live in the alias, no ability
 * name is ever mistaken for a keyword.
 *
 * Reachability is target-free by nature, so the first parameter is always a
 * schema key (not a route-model parameter): the guard answers "could this user
 * ever …?", which no specific row could change.
 */
abstract class AbstractReachabilityMiddleware
{
    /**
     * Which reachability results let the request through.
     */
    abstract protected function passes(Reachability $reachability): bool;

    /**
     * How multiple abilities combine. ALL: every ability must pass; ANY: one is enough.
     */
    protected function matchMode(): AbilityMatchMode
    {
        return AbilityMatchMode::ALL;
    }

    public function handle(
        Request $request,
        Closure $next,
        string $target,
        string ...$abilities,
    ): Response {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            abort(403);
        }

        if ($abilities === []) {
            throw new InvalidArgumentException('Warden reachability middleware requires at least one ability.');
        }

        try {
            $schemaClass = Warden::getSchemaForKey($target);
        } catch (OutOfBoundsException) {
            throw new InvalidArgumentException(
                sprintf('Unable to resolve Warden schema for [%s]; reachability guards take a schema key.', $target)
            );
        }

        $satisfied = (new $schemaClass)->reachabilitySatisfies(
            $user,
            $abilities,
            fn (Reachability $reachability): bool => $this->passes($reachability),
            $this->matchMode(),
        );

        if (! $satisfied) {
            abort(403);
        }

        return $next($request);
    }
}
