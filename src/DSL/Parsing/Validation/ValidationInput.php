<?php

namespace Warrant\DSL\Parsing\Validation;

use Warrant\DSL\SchemaCatalog;

/**
 * What rule text is validated against, beyond the text itself.
 *
 * Two inputs that are equal (`==`) validate any text alike.
 *
 * @internal
 */
final readonly class ValidationInput
{
    /**
     * @param SchemaCatalog|null $catalog Where the schema each `for` header
     *   names is found, and the schemas cross-schema references name; null when
     *   no schema can be known, so the text is held only to the rules of the
     *   language that need none, and no header is reported as unknown.
     * @param string|null $schema The key of the schema rules or a condition with
     *   no `for` header are in, when the caller knows it. A `for` header names
     *   the schema of the body under it, whatever this says. A key the catalog
     *   does not hold is no schema at all.
     * @param GenericMode $genericMode Whether rules with no `for` header name
     *   their abilities.
     */
    public function __construct(
        public ?SchemaCatalog $catalog,
        public ?string $schema = null,
        public GenericMode $genericMode = GenericMode::Either,
    ) {
    }
}
