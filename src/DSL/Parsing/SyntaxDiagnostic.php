<?php

namespace Warrant\DSL\Parsing;

/**
 * One syntax error found in rule text read tolerantly, where reading carries on
 * past the error instead of throwing it.
 *
 * The range is the text the error is about, which is not always a whole token: an
 * invalid escape covers the two bytes of the escape inside an otherwise sound
 * string, and an unterminated string covers only its opening quote.
 */
final readonly class SyntaxDiagnostic
{
    /**
     * @param string $message The message strict reading throws for this error.
     * @param int $offset 0-based byte offset of the first byte the error covers.
     * @param int $endOffset 0-based byte offset just past the last byte it covers.
     */
    public function __construct(
        public string $message,
        public int $offset,
        public int $endOffset,
    ) {
    }
}
