<?php

namespace Warrant\Middleware;

use Warrant\Reachability;

/** `warrant.always` — passes when every ability is guaranteed (ALWAYS). */
class AlwaysMiddleware extends AbstractReachabilityMiddleware
{
    protected function passes(Reachability $reachability): bool
    {
        return $reachability === Reachability::ALWAYS;
    }
}
