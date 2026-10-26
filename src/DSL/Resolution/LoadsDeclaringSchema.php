<?php

namespace Warrant\DSL\Resolution;

use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * Implements {@see IFoundConstruct} over the `$declaredIn` and `$schemaClass`
 * a found construct holds, loading the schema that declares it through its id.
 */
trait LoadsDeclaringSchema
{
    public function declaredIn(): ISchemaId
    {
        return $this->declaredIn;
    }

    public function schemaClass(): string
    {
        return $this->schemaClass;
    }

    public function schema(): ISchema
    {
        return $this->declaredIn->schema();
    }
}
