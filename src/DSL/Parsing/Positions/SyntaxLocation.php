<?php

namespace Warrant\DSL\Parsing\Positions;

use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * Where a node came from: the {@see SyntaxSource} it belongs to, and, for a node
 * read from rule text, the span of it there and the span of each of its parts. A
 * part is a value inside the node that is no node of its own, such as a
 * condition's name or one of its arguments, found by the name the node declares
 * for it and its index or key.
 *
 * A node built in code was written nowhere: its source is the
 * {@see SyntaxProducer} that built it, with no text.
 */
final readonly class SyntaxLocation
{
    /**
     * @param SyntaxSource $source Where the node came from.
     * @param Span|null $span Where in its source's text the node was written;
     *   null for a node built in code.
     * @param array<string, array<int|string, Span>> $parts Each part's span, by
     *   part name and then by its index or key; a part with neither is under ''.
     */
    private function __construct(
        public SyntaxSource $source,
        public ?Span $span,
        public array $parts,
    ) {}

    /**
     * @param array<string, array<int|string, Span>> $parts
     */
    public static function in(SyntaxSource $source, Span $span, array $parts = []): self
    {
        return new self($source, $span, $parts);
    }

    public static function builtBy(SyntaxProducer $producer): self
    {
        return new self(new SyntaxSource(null, $producer), null, []);
    }

    /**
     * Whether the node was read from rule text, so it has a place in it.
     */
    public function isWritten(): bool
    {
        return $this->span !== null;
    }

    /**
     * Where the node, or its $part at $key, was written; null when it was not.
     */
    public function spanOf(?string $part = null, int|string|null $key = null): ?Span
    {
        if ($part === null) {
            return $this->span;
        }

        return $this->parts[$part][$key ?? ''] ?? null;
    }
}
