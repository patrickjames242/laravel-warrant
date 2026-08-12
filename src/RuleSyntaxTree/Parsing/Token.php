<?php

namespace Warrant\RuleSyntaxTree\Parsing;

readonly class Token
{
    /**
     * @param TokenType $type   The lexical category of this token.
     * @param string    $lexeme The exact source text of the token.
     * @param int       $offset 0-based character offset of the token's first char.
     * @param int       $line   1-based line number of the token's first char.
     * @param int       $col    1-based column number of the token's first char.
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
}
