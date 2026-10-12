<?php

namespace Warrant\DSL\Parsing;

use RuntimeException;
use Warrant\DSL\Lexing\Token;

/**
 * Thrown eagerly when raw Warrant syntax (or its bindings) is malformed. Carries
 * the position of the offending token so the error is debuggable even when an
 * entire rule set sits on a single line.
 *
 * Note: the position lives in {@see $sourceLine} / {@see $sourceColumn} rather
 * than the base Exception's own $line, which refers to the PHP file location.
 */
class WarrantSyntaxException extends RuntimeException
{
    /**
     * @param string $reason The error alone, without the position and the
     *   excerpt of the source that the exception's message adds to it.
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $source,
        public readonly int $offset,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
    ) {
        parent::__construct($this->decorate($reason));
    }

    /**
     * Build an exception from a token, pointing at where it begins.
     */
    public static function at(string $message, string $source, Token $token): self
    {
        return new self($message, $source, $token->offset, $token->line, $token->col);
    }

    /**
     * Build an exception at an explicit source position.
     */
    public static function atOffset(string $message, string $source, int $offset, int $line, int $col): self
    {
        return new self($message, $source, $offset, $line, $col);
    }

    /**
     * Build an exception at byte $offset of $source, counting its line and
     * column as the lexer does: a line ends at each "\n".
     */
    public static function atSourceOffset(string $message, string $source, int $offset): self
    {
        $before = substr($source, 0, $offset);
        $lineBreak = strrpos($before, "\n");
        $lineStart = $lineBreak === false ? 0 : $lineBreak + 1;

        return new self($message, $source, $offset, substr_count($before, "\n") + 1, $offset - $lineStart + 1);
    }

    private function decorate(string $message): string
    {
        return sprintf('%s (line %d, column %d)%s', $message, $this->sourceLine, $this->sourceColumn, $this->snippet());
    }

    /**
     * Render the offending line with a caret under the reported column.
     */
    private function snippet(): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $this->source) ?: [];
        $lineText = $lines[$this->sourceLine - 1] ?? null;

        if ($lineText === null) {
            return '';
        }

        $caret = str_repeat(' ', max(0, $this->sourceColumn - 1)) . '^';

        return "\n\n    " . $lineText . "\n    " . $caret;
    }
}
