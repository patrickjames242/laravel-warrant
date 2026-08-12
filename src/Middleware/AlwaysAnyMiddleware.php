<?php

namespace Warden\Middleware;

use Warden\AbilityMatchMode;

/** `warden.always.any` — passes when any listed ability is guaranteed. */
class AlwaysAnyMiddleware extends AlwaysMiddleware
{
    protected function matchMode(): AbilityMatchMode
    {
        return AbilityMatchMode::ANY;
    }
}
