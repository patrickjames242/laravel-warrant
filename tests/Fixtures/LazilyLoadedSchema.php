<?php

declare(strict_types=1);

namespace Warrant\Tests\Fixtures;

use Warrant\Ability;
use Warrant\Schema\WarrantSchema;

/**
 * Registered by the laziness test and never referenced anywhere else, so the test
 * can assert that registering a schema does not load it — nor its model.
 */
class LazilyLoadedSchema extends WarrantSchema
{
    public const model = LazilyLoadedModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';
}
