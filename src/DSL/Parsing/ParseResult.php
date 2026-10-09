<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Positions\SourceMap;

/**
 * Parsed rule text together with where each node of it was written, for tooling
 * that has to point back into the text.
 */
final readonly class ParseResult
{
    public function __construct(
        public WarrantSyntax $syntax,
        public SourceMap $positions,
    ) {
    }
}
