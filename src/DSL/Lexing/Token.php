<?php

namespace Warrant\DSL\Lexing;

readonly class Token
{
    /**
     * @param TokenType $type   The lexical category of this token.
     * @param string    $lexeme The exact source text of the token.
     * @param int       $offset 0-based byte offset of the token's first byte.
     * @param int       $line   1-based line number of the token's first byte,
     *                          counting `\n` line breaks.
     * @param int       $col    1-based byte column of the token's first byte.
     * @param mixed     $value  Resolved PHP value for literals (STRING/INT/FLOAT/
     *                          BOOL/NULL); the binding name for NAMED_BINDING; null
     *                          otherwise.
     */
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $offset,
        public int $line,
        public int $col,
        public mixed $value = null,
    ) {
    }

    /**
     * The 0-based byte offset just past the token's last byte. The lexeme is the
     * token's exact source text, so its length is the token's width in the source.
     */
    public function endOffset(): int
    {
        return $this->offset + strlen($this->lexeme);
    }
}
