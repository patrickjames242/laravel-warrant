<?php

namespace Warrant\Middleware;

use Warrant\AbilityMatchMode;

/** `warrant.could-ever.any` — passes when any listed ability is reachable. */
class CouldEverAnyMiddleware extends CouldEverMiddleware
{
    protected function matchMode(): AbilityMatchMode
    {
        return AbilityMatchMode::ANY;
    }
}
