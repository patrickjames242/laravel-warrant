<?php

namespace Warrant\DSL\Parsing;

/**
 * Where syntax came from: the {@see SyntaxProducer} that produced it, and, when
 * it was written as rule text, that text as it was handed over. Every node
 * parsed from one block of text is placed in the same source; nodes built in
 * code share one with no text once what produced them is known.
 */
final readonly class SyntaxSource
{
    /**
     * @param BoundSyntax|null $bound The syntax as it was handed over, with the
     *   values for its bindings; null for nodes built in code.
     */
    public function __construct(
        public ?BoundSyntax $bound,
        public SyntaxProducer $producer,
    ) {}
}
