<?php

namespace App\Schemas;

use Attribute;
use Warrant\Schema\Abilities\IDeclaresAbility;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class TenantAbility implements IDeclaresAbility
{
    public function requiredContext(): array
    {
        return ['tenant'];
    }
}
