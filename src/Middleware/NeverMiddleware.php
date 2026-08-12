<?php

namespace Warden\Middleware;

use Warden\Reachability;

/** `warden.never` — passes when every ability is impossible (NEVER). */
class NeverMiddleware extends AbstractReachabilityMiddleware
{
    protected function passes(Reachability $reachability): bool
    {
        return $reachability === Reachability::NEVER;
    }
}
