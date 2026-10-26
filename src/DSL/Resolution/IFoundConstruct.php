<?php

namespace Warrant\DSL\Resolution;

use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * A construct a name resolved to: a condition or a rule template, and the
 * schema that declares it.
 */
interface IFoundConstruct
{
    /**
     * The schema that declares the construct: a keyed one by its key, or a
     * global one by its class.
     */
    public function declaredIn(): ISchemaId;

    /**
     * The class of the schema that declares the construct.
     *
     * @return class-string<ISchema>
     */
    public function schemaClass(): string;

    /**
     * The schema that declares the construct, which the construct is invoked on.
     */
    public function schema(): ISchema;
}
