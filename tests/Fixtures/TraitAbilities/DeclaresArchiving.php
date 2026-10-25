<?php

declare(strict_types=1);

namespace Warrant\Tests\Fixtures\TraitAbilities;

use Warrant\Schema\Abilities\Ability;

/**
 * Used by {@see DeclaresPublishing}, so its ability reaches the schema through
 * a trait used by a trait.
 */
trait DeclaresArchiving
{
    #[Ability]
    public const ARCHIVE = 'archive';
}
