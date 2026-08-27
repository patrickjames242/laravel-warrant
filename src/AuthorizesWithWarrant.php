<?php

namespace Warrant;

use Warrant\Facades\Warrant;

/**
 * Add to an Authenticatable user model to reach that user's Warrant engine
 * directly: `$user->warrant()` returns a {@see WarrantGuard} bound to `$this`,
 * equivalent to `Warrant::guard($user)`. From there, `->forSchema(...)` and the
 * check/reachability helpers are available.
 */
trait AuthorizesWithWarrant
{
    public function warrant(): WarrantGuard
    {
        return Warrant::guard($this);
    }
}
