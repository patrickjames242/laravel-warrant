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

    /**
     * $reason followed by where it is: the line and column, then the line of
     * the source with a caret under the column.
     */
    public function describe(string $reason): string
    {
        return sprintf('%s (line %d, column %d)%s', $reason, $this->line, $this->column, $this->excerpt());
    }

    private function excerpt(): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $this->source) ?: [];
        $lineText = $lines[$this->line - 1] ?? null;

        if ($lineText === null) {
            return '';
        }

        $caret = str_repeat(' ', max(0, $this->column - 1)) . '^';

        return "\n\n    " . $lineText . "\n    " . $caret;
    }
}
