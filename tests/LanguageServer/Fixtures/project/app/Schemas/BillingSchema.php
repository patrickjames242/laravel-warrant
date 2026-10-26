<?php

namespace App\Schemas;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Types\Classes\RowlessSchema;

final class BillingSchema extends RowlessSchema
{
    #[Ability]
    public const MANAGE = 'manage';
}
