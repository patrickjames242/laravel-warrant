<?php

namespace Warrant\Schema\Relations;

/**
 * A row relation a schema declares, as data: the name rule text uses for it, the
 * method that builds it, and the schema whose rows it reaches.
 */
final readonly class RowRelationDefinition
{
    /**
     * @param class-string $targetSchemaClass
     */
    public function __construct(
        public string $key,
        public string $methodName,
        public string $targetSchemaClass,
    ) {
    }
}
