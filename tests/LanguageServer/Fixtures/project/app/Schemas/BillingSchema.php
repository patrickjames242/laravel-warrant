<?php

namespace App\Schemas;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\WarrantSchema;

final class BillingSchema extends WarrantSchema
{
    #[Ability]
    public const MANAGE = 'manage';
}
