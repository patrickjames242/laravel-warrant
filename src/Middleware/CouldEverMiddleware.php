<?php

namespace Warden\Middleware;

use Warden\Reachability;

/** `warden.could-ever` — passes when every ability is reachable (not NEVER). */
class CouldEverMiddleware extends AbstractReachabilityMiddleware
{
    protected function passes(Reachability $reachability): bool
    {
        return $reachability !== Reachability::NEVER;
    }
}
