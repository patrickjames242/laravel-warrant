<?php

declare(strict_types=1);

namespace Warrant\Tests\Fixtures\TraitAbilities;

use Warrant\Schema\Ability;

/**
 * Declares abilities for {@see TraitAbilitiesSchema}, which uses it.
 */
trait DeclaresPublishing
{
    use DeclaresArchiving;

    #[Ability]
    public const PUBLISH = 'publish';
}
