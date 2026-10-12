<?php

namespace Warrant\DSL\Parsing;

/**
 * One error found in rule text by a tolerant reading or a reporting validation,
 * which carry on past an error instead of throwing it: a lexical or syntax error,
 * or a rule of the language the text breaks.
 *
 * The range is the text the error is about, which is not always a whole token: an
 * invalid escape covers the two bytes of the escape inside an otherwise sound
 * string, an unterminated string covers only its opening quote, and an undeclared
 * ability covers that ability's name.
 */
final readonly class Diagnostic
{
    /**
     * @param string $message The message a strict reading or validation throws
     *   for this error, without its position.
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
