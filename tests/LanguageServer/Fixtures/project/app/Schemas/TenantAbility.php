<?php

namespace App\Schemas;

use Attribute;
use Warrant\Schema\DeclaresAbility;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class TenantAbility implements DeclaresAbility
{
    public function requiredContext(): array
    {
        return ['tenant'];
    }
}
