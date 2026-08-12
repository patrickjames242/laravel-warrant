<?php

namespace Warrant\Middleware;

use Warrant\Reachability;

/** `warrant.never` — passes when every ability is impossible (NEVER). */
class NeverMiddleware extends AbstractReachabilityMiddleware
{
    protected function passes(Reachability $reachability): bool
    {
        return $reachability === Reachability::NEVER;
    }
}
