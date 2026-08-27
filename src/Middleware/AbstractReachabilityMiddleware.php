<?php

namespace Warrant\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Warrant\AbilityMatchMode;
use Warrant\Facades\Warrant;
use Warrant\Reachability;

/**
 * Base for the structural reachability route guards
 * (`warrant.could-ever`, `warrant.always`, `warrant.never`, each with a `.any`
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
            throw new InvalidArgumentException('Warrant reachability middleware requires at least one ability.');
        }

        $schemaClass = Warrant::registry()->resolveSchemaClassOrNull($target);

        if ($schemaClass === null) {
            throw new InvalidArgumentException(
                sprintf('Unable to resolve Warrant schema for [%s]; reachability guards take a schema key.', $target)
            );
        }

        $satisfied = Warrant::forSchema($schemaClass, $user)->reachabilitySatisfies(
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
