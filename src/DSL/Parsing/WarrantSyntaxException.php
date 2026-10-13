<?php

namespace Warrant\DSL\Parsing;

use RuntimeException;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Parsing\Positions\SourcePosition;

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
     * @param DiagnosticCode $diagnosticCode Which kind of mistake this is, as a
     *   {@see Diagnostic} made from it carries it. Named so as not to be the base
     *   Exception's own integer $code.
     * @param string $reason The error alone, without the position and the
     *   excerpt of the source that the exception's message adds to it.
     * @param string $source The text the error is in.
     * @param int $offset 0-based byte offset in $source the error is at.
     * @param int $sourceLine 1-based line of $offset.
     * @param int $sourceColumn 1-based column of $offset.
     */
    public function __construct(
        public readonly DiagnosticCode $diagnosticCode,
        public readonly string $reason,
        public readonly string $source,
        public readonly int $offset,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
    ) {
        parent::__construct((new SourcePosition($source, $offset, $sourceLine, $sourceColumn))->describe($reason));
    }

    /**
     * Build an exception from a token, pointing at where it begins.
     */
    public static function at(DiagnosticCode $code, string $message, string $source, Token $token): self
    {
        return new self($code, $message, $source, $token->offset, $token->line, $token->col);
    }

    /**
     * Build an exception at an explicit source position.
     */
    public static function atOffset(DiagnosticCode $code, string $message, string $source, int $offset, int $line, int $col): self
    {
        return new self($code, $message, $source, $offset, $line, $col);
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
