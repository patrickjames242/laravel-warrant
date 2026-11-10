<?php

namespace Warrant\Schema\Types\Interfaces;

use Warrant\Schema\Conditions\Row\IProvidesRowConditions;
use Warrant\Schema\Relations\IProvidesRowRelations;
use Warrant\Schema\Rows\IProvidesRows;

/**
 * A schema whose abilities are about rows, each of which can be named.
 */
interface IRowSchema extends IAbilitySchema, IProvidesRows, IProvidesRowConditions, IProvidesRowRelations
{
}
