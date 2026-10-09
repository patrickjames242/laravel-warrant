<?php

namespace Warrant\DSL\Parsing\Positions;

use Warrant\DSL\Lexing\Token;

/**
 * A range of rule text, in bytes.
 */
final readonly class Span
{
    /**
     * @param int $offset 0-based byte offset of the first byte.
     * @param int $endOffset 0-based byte offset just past the last byte.
     */
    public function __construct(
        public int $offset,
        public int $endOffset,
    ) {
    }

    /**
     * The range from the start of $start to the end of $end.
     */
    public static function between(Token $start, Token $end): self
    {
        return new self($start->offset, $end->endOffset());
    }

    /**
     * Whether $offset is inside the range or just past its end, where a cursor
     * sits after typing the range's last character.
     */
    public function contains(int $offset): bool
    {
        return $this->offset <= $offset && $offset <= $this->endOffset;
    }

    public function length(): int
    {
        return $this->endOffset - $this->offset;
    }
}
