<?php

namespace Warrant\Schema\Types\Interfaces;

/**
 * A schema whose rule templates and conditions any rule may use without naming
 * it. It declares no abilities, so no check is ever made against it.
 */
interface IGlobalSchema extends ISchema
{
}
