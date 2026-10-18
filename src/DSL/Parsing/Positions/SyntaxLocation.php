<?php

namespace Warrant\DSL\Parsing\Positions;

use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * Where a node came from: the {@see SyntaxSource} it belongs to, and, for a node
 * read from rule text, the span of it there. Everything the text writes is a
 * node, down to each name and literal, so every piece of a rule has a place of
 * its own.
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
     */
    private function __construct(
        public SyntaxSource $source,
        public ?Span $span,
    ) {}

    public static function in(SyntaxSource $source, Span $span): self
    {
        return new self($source, $span);
    }

    public static function builtBy(SyntaxProducer $producer): self
    {
        return new self(new SyntaxSource(null, $producer), null);
    }

    /**
     * Whether the node was read from rule text, so it has a place in it.
     */
    public function isWritten(): bool
    {
        return $this->span !== null;
    }
}
