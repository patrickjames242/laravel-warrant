<?php

declare(strict_types=1);

namespace Warrant\Tests\Fixtures\TraitAbilities;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\WarrantSchema;

/**
 * A schema with abilities of its own and abilities from traits, for reading
 * where each one is declared.
 */
class TraitAbilitiesSchema extends WarrantSchema
{
    use DeclaresPublishing;

    #[Ability]
    public const VIEW = 'view';
}
