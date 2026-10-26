<?php

namespace Warrant\DSL\Resolution;

use Warrant\Facades\Warrant;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * A schema listed under its key in the `warrant.schemas` config, as opposed to a
 * global schema, which has none.
 */
final readonly class KeyedSchemaId implements ISchemaId
{
    public function __construct(public string $key)
    {
    }

    public function schema(): ISchema
    {
        return Warrant::registry()->resolveOrFail($this->key)->schema;
    }
}
