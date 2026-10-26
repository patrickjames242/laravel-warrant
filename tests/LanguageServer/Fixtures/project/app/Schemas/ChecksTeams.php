<?php

namespace App\Schemas;

use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;

trait ChecksTeams
{
    /**
     * Whether the user belongs to the given team.
     *
     * @param mixed $team
     */
    #[GlobalCondition]
    public function inTeam(GlobalConditionContext $c, mixed $team, mixed $role = null): bool
    {
        return true;
    }
}
