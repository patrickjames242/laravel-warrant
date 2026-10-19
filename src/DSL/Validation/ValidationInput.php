<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\SchemaCatalog;

/**
 * What rule text is validated against, beyond the text itself. The schema the
 * text is for, and whether its rules with no `for` header name their
 * abilities, are what produced the text: they come from its
 * {@see \Warrant\DSL\Parsing\SyntaxProducer}, not from here.
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
     * @param bool $checkBindingValues Whether each binding is checked against
     *   the value its source gives it: that it has one, that every value given
     *   is used, and that it can stand where it was written. Off where the text
     *   was read without its values, as an editor reads it; whether named and
     *   positional bindings are mixed is checked either way.
     */
    public function __construct(
        public ?SchemaCatalog $catalog,
        public bool $checkBindingValues = true,
    ) {
    }
}
