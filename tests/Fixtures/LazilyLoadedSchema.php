<?php

declare(strict_types=1);

namespace Warrant\Tests\Fixtures;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Types\Classes\ModelSchema;

/**
 * Registered by the laziness test and never referenced anywhere else, so the test
 * can assert that registering a schema does not load it — nor its model.
 */
class LazilyLoadedSchema extends ModelSchema
{
    public const model = LazilyLoadedModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';
}
