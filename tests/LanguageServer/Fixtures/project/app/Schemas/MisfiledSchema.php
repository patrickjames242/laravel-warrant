<?php

namespace App\Schemas;

use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Types\Classes\RowlessSchema;

/**
 * Listed under `global`, but not a global schema.
 */
final class MisfiledSchema extends RowlessSchema
{
    #[GlobalCondition]
    public function isMisfiled(GlobalConditionContext $c): bool
    {
        return true;
    }
}
