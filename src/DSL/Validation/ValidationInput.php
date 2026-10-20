<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\SchemaCatalog;

/**
 * What rule text is validated against, beyond the text itself: the schemas it
 * can name, whether its bindings' values are checked, and what produced it. The
 * producer says which schema the text's rules with no `for` header belong to and
 * whether they name their abilities; it is the one the text's source had when
 * the text was validated, which {@see \Warrant\DSL\Parsing\ASTNodes\ValidSyntax}
 * fills in, since a source's producer is set again each time the text is handed
 * over.
 *
 * Two inputs that are {@see isSameAs()} the same validate any text alike.
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
     * @param SyntaxProducer|null $producer What produced the text when it was
     *   validated; null when nothing had handed it over.
     */
    public function __construct(
        public ?SchemaCatalog $catalog,
        public bool $checkBindingValues = true,
        public ?SyntaxProducer $producer = null,
    ) {
    }

    /**
     * This input, with $producer as what produced the text.
     */
    public function withProducer(?SyntaxProducer $producer): self
    {
        return new self($this->catalog, $this->checkBindingValues, $producer);
    }

    /**
     * Whether $other validates any text as this does: an equal catalog and the
     * same binding checks, and the same producer object, since the arguments
     * its invoking node passed in are skipped by identity.
     */
    public function isSameAs(self $other): bool
    {
        return $this->catalog == $other->catalog
            && $this->checkBindingValues === $other->checkBindingValues
            && $this->producer === $other->producer;
    }
}
