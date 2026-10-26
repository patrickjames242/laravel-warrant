<?php

namespace App\Schemas;

use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\GlobalSchema;

/**
 * What every schema's rules may ask about the tenant.
 */
final class TenancySchema extends GlobalSchema
{
    /**
     * Whether the check is made in the user's own tenant.
     */
    #[GlobalCondition]
    public function inCurrentTenant(GlobalConditionContext $c): bool
    {
        return true;
    }

    #[GlobalCondition]
    public function isStaff(GlobalConditionContext $c): bool
    {
        return true;
    }

    #[DerivedCondition]
    public function isTenantStaff(): string
    {
        return 'is_staff and in_current_tenant';
    }

    #[RuleTemplate]
    public function tenantOnly(): string
    {
        return 'if in_current_tenant they can';
    }
}
