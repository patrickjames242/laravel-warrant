<?php

namespace App\Schemas;

use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\GlobalCondition;

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
