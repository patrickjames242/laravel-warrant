<?php

namespace Warrant\DSL\Parsing\Positions;

/**
 * Where one node, or one part of a node, was written.
 */
final readonly class SourceEntry
{
    /**
     * @param object $node The node the entry is about.
     * @param string|null $part The property of $node the entry is about, or null
     *   for the whole node.
     * @param int|string|null $key Which item of that property, when it is a list
     *   or a map; null otherwise.
     */
    public function __construct(
        public object $node,
        public ?string $part,
        public int|string|null $key,
        public Span $span,
    ) {
    }
}
