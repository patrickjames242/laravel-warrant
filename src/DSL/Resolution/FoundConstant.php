<?php

namespace Warrant\DSL\Resolution;

use Warrant\Schema\Constants\ConstantDefinition;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * The constant a name resolved to, and the schema that declares it: by its key
 * or, for a global, its class, and by its class either way.
 */
final readonly class FoundConstant implements IFoundConstruct
{
    use LoadsDeclaringSchema;

    /**
     * @param class-string<ISchema> $schemaClass
     */
    public function __construct(
        public ConstantDefinition $definition,
        private ISchemaId $declaredIn,
        private string $schemaClass,
    ) {
    }
}
