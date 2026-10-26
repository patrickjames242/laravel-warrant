<?php

namespace Warrant\Registry;

use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * A registered schema and the key it is registered under.
 *
 * The key belongs to the registration, not to the schema: a schema does not know
 * the key it is registered under. So whoever resolves a reference hands the key on
 * with the schema, and nothing downstream has to look it up again.
 */
final readonly class ResolvedSchema
{
    public function __construct(
        public string $key,
        public ISchema $schema,
    ) {
    }
}
