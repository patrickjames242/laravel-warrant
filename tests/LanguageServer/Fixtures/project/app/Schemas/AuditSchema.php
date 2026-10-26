<?php

namespace App\Schemas;

use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Types\Classes\GlobalSchema;

/**
 * Declares a name TenancySchema, listed before it, declares too.
 */
final class AuditSchema extends GlobalSchema
{
    #[GlobalCondition]
    public function isStaff(GlobalConditionContext $c): bool
    {
        return true;
    }
}
