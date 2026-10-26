<?php

namespace Warrant\Schema\Types\Interfaces;

use Warrant\Schema\Conditions\Derived\IProvidesDerivedConditions;
use Warrant\Schema\Conditions\Global\IProvidesGlobalConditions;
use Warrant\Schema\RuleTemplates\IProvidesRuleTemplates;

/**
 * What every schema has: rule templates, and the conditions that need no row.
 */
interface ISchema extends IProvidesRuleTemplates, IProvidesGlobalConditions, IProvidesDerivedConditions
{
}
