<?php

namespace Warrant\Middleware;

use Warrant\Reachability;

/** `warrant.could-ever` — passes when every ability is reachable (not NEVER). */
class CouldEverMiddleware extends AbstractReachabilityMiddleware
{
    protected function passes(Reachability $reachability): bool
    {
        return $reachability !== Reachability::NEVER;
    }
}
