<?php

namespace Warrant\Middleware;

use Warrant\AbilityMatchMode;

/** `warrant.never.any` — passes when any listed ability is impossible. */
class NeverAnyMiddleware extends NeverMiddleware
{
    protected function matchMode(): AbilityMatchMode
    {
        return AbilityMatchMode::ANY;
    }
}
