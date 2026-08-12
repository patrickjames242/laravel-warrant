<?php

namespace Warrant\Middleware;

use Warrant\AbilityMatchMode;

/** `warrant.always.any` — passes when any listed ability is guaranteed. */
class AlwaysAnyMiddleware extends AlwaysMiddleware
{
    protected function matchMode(): AbilityMatchMode
    {
        return AbilityMatchMode::ANY;
    }
}
