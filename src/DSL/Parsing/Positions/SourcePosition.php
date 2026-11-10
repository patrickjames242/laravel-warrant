<?php

namespace Warrant\DSL\Parsing\Positions;

/**
 * One place in a text, as an error reports it: a byte offset, and the line and
 * column it is on.
 */
final readonly class SourcePosition
{
    public function __construct(
        public string $source,
        public int $offset,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * Byte $offset of $source, with its line and column counted as the lexer
     * counts them: a line ends at each "\n".
     */
    public static function at(string $source, int $offset): self
    {
        $before = substr($source, 0, $offset);
        $lineBreak = strrpos($before, "\n");
        $lineStart = $lineBreak === false ? 0 : $lineBreak + 1;

        return new self($source, $offset, substr_count($before, "\n") + 1, $offset - $lineStart + 1);
    }
}
