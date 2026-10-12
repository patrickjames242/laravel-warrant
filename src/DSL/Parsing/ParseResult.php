<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Positions\SourceMap;

/**
 * Parsed rule text together with where each node of it was written, for tooling
 * that has to point back into the text, and the syntax errors found in it.
 */
final readonly class ParseResult
{
    /**
     * @param list<SyntaxDiagnostic> $diagnostics Every syntax error, when the
     *   text was read with {@see WarrantParser::analyze()}. A strict read throws
     *   its error instead, so it has none.
     */
    public function __construct(
        public WarrantSyntax $syntax,
        public SourceMap $positions,
        public array $diagnostics = [],
    ) {
    }
}
