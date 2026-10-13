<?php

namespace App\Schemas;

use Warrant\Schema\Ability;
use Warrant\Schema\StandardAbilities;
use Warrant\Schema\WarrantSchema;

abstract class BaseSchema extends WarrantSchema
{
    #[Ability]
    public const VIEW = StandardAbilities::VIEW;
}
