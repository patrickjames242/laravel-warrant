<?php

namespace Warrant\DSL\Resolution;

use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * One schema: a keyed one, listed under its key in `schemas`, or a global one,
 * named by its class, since a global has no key.
 *
 * It names the schema a piece of rule text was written in, and the schema that
 * declares the construct a name resolved to.
 */
interface ISchemaId
{
    /**
     * The schema this names, from the registry: built on first use, and the
     * same instance every time after.
     */
    public function schema(): ISchema;
}
