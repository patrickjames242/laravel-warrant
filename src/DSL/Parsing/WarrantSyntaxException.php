<?php

namespace Warrant\DSL\Parsing;

use RuntimeException;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Parsing\Positions\RuleTextExcerpt;
use Warrant\DSL\Parsing\Positions\SourcePosition;

/**
 * Thrown eagerly when raw Warrant syntax (or its bindings) is malformed. Carries
 * the position of the offending token so the error is debuggable even when an
 * entire rule set sits on a single line.
 *
 * The position in the text lives in {@see $sourceLine} / {@see $sourceColumn}.
 * When the file the text is written in is known, the exception's own file and
 * line are the place in it, so an error page opens the rule text; otherwise
 * they are where the exception was thrown.
 */
class WarrantSyntaxException extends RuntimeException
{
    /** Where in the text the error is, which can lay out the lines around it. */
    public readonly RuleTextExcerpt $excerpt;

    /**
     * @param DiagnosticCode $diagnosticCode Which kind of mistake this is, as a
     *   {@see Diagnostic} made from it carries it. Named so as not to be the base
     *   Exception's own integer $code.
     * @param string $reason The error alone, without the position the
     *   exception's message adds to it.
     * @param string $source The text the error is in.
     * @param int $offset 0-based byte offset in $source the error is at.
     * @param int $sourceLine 1-based line of $offset.
     * @param int $sourceColumn 1-based column of $offset.
     * @param SyntaxSource|null $origin Where the text came from, to find the
     *   file it is written in; null for a tolerant read, which never shows the
     *   message, or when nothing is known.
     * @param int|null $endOffset Just past the last byte the error covers; null
     *   for the one byte at $offset.
     */
    public function __construct(
        public readonly DiagnosticCode $diagnosticCode,
        public readonly string $reason,
        public readonly string $source,
        public readonly int $offset,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
        public readonly ?SyntaxSource $origin = null,
        public readonly ?int $endOffset = null,
    ) {
        $this->excerpt = $origin === null
            ? RuleTextExcerpt::inText($source, $offset, $endOffset ?? $offset + 1)
            : RuleTextExcerpt::of($source, $offset, $endOffset ?? $offset + 1, $origin);

        parent::__construct($this->excerpt->summary($reason));

        if ($this->excerpt->file !== null) {
            $this->file = $this->excerpt->file->path;
            $this->line = $this->excerpt->line();
        }
    }

    /**
     * Build an exception from a token, covering it.
     *
     * @param SyntaxSource|null $origin Where the text came from, when the error
     *   will be thrown.
     */
    public static function at(DiagnosticCode $code, string $message, string $source, Token $token, ?SyntaxSource $origin = null): self
    {
        return new self($code, $message, $source, $token->offset, $token->line, $token->col, $origin, $token->endOffset());
    }

    /**
     * Build an exception at an explicit source position.
     */
    public static function atOffset(DiagnosticCode $code, string $message, string $source, int $offset, int $line, int $col, ?int $endOffset = null): self
    {
        return new self($code, $message, $source, $offset, $line, $col, endOffset: $endOffset);
    }

    /**
     * This error, placed in the text $origin holds, so its message can name the
     * file the text is written in.
     */
    public function from(SyntaxSource $origin): self
    {
        return new self($this->diagnosticCode, $this->reason, $this->source, $this->offset, $this->sourceLine, $this->sourceColumn, $origin, $this->endOffset);
    }

    /**
     * Build an exception at byte $offset of $source, counting its line and
     * column as the lexer does: a line ends at each "\n".
     */
    public static function atSourceOffset(DiagnosticCode $code, string $message, string $source, int $offset): self
    {
        $position = SourcePosition::at($source, $offset);

        return new self($code, $message, $source, $offset, $position->line, $position->column);
    }
}
