<?php

namespace App\Schemas;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Abilities\StandardAbilities;
use Warrant\Schema\WarrantSchema;

abstract class BaseSchema extends WarrantSchema
{
    #[Ability]
    public const VIEW = StandardAbilities::VIEW;
}
