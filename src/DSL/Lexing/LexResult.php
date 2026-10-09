<?php

namespace Warrant\DSL\Lexing;

use Warrant\DSL\Parsing\SyntaxDiagnostic;

/**
 * Everything a tolerant {@see Lexer::scan()} read from one source: its tokens,
 * and the syntax errors among them.
 */
final readonly class LexResult
{
    /**
     * @param list<Token> $tokens Every token in source order, ending in EOF. Text
     *   the lexer could not read is an {@see TokenType::ERROR} token.
     * @param list<SyntaxDiagnostic> $diagnostics Every syntax error, in source
     *   order.
     */
    public function __construct(
        public array $tokens,
        public array $diagnostics,
    ) {
    }
}
