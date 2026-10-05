<?php

namespace Warrant\DSL\Parsing\Writing;

/**
 * A syntax tree, or a node of one, rendered back to the string DSL with every
 * value extracted as a positional `?` placeholder, paired with the flat,
 * left-to-right list of values that fill them.
 *
 * Round-trips through the parser:
 * `WarrantSyntax::parse($syntax, $bindings)` reconstructs an equivalent tree,
 * with any schema riding in the rendered `for` header.
 */
readonly class BoundSyntax
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public string $syntax,
        public array $bindings,
    ) {
    }
}
