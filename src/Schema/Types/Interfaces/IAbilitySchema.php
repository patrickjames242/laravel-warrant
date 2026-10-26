<?php

namespace Warrant\Schema\Types\Interfaces;

use Warrant\Schema\Abilities\IProvidesAbilities;
use Warrant\Schema\Context\IProvidesContext;
use Warrant\Schema\Denials\IProvidesDenialMessages;
use Warrant\Schema\Rules\IProvidesRules;

/**
 * A schema checks are made against: it declares abilities, the rules about them,
 * a context policy, and what to say when a check is denied.
 */
interface IAbilitySchema extends ISchema, IProvidesAbilities, IProvidesRules, IProvidesContext, IProvidesDenialMessages
{
}
