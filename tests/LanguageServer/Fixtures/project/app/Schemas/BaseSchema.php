<?php

namespace App\Schemas;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Abilities\StandardAbilities;
use Warrant\Schema\Types\Classes\ModelSchema;

abstract class BaseSchema extends ModelSchema
{
    #[Ability]
    public const VIEW = StandardAbilities::VIEW;
}
