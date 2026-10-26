<?php

namespace Warrant\DSL\Resolution;

use Warrant\Schema\RuleTemplates\RuleTemplateDefinition;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * The rule template a name resolved to, and the schema that declares it: by its
 * key or, for a global, its class, and by its class either way.
 */
final readonly class FoundRuleTemplate implements IFoundConstruct
{
    use LoadsDeclaringSchema;

    /**
     * @param class-string<ISchema> $schemaClass
     */
    public function __construct(
        public RuleTemplateDefinition $definition,
        private ISchemaId $declaredIn,
        private string $schemaClass,
    ) {
    }
}
