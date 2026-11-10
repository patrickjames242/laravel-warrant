<?php

namespace Warrant\DSL\Resolution;

use Warrant\Schema\Relations\RowRelationDefinition;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * The row relation an `its <name>` resolved to: the schema that declares it, by
 * its key and class, and the key of the schema whose rows it reaches.
 */
final readonly class FoundRowRelation implements IFoundConstruct
{
    use LoadsDeclaringSchema;

    /**
     * @param class-string<ISchema> $schemaClass
     * @param string|null $targetKey The key the relation's target schema is
     *   registered under, or null when its class is not a registered schema.
     */
    public function __construct(
        public RowRelationDefinition $definition,
        private ISchemaId $declaredIn,
        private string $schemaClass,
        public ?string $targetKey,
    ) {
    }
}
