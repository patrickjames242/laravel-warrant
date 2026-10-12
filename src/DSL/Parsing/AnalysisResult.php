<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

/**
 * What {@see WarrantParser::analyze()} found in a text: the tree it could read,
 * and everything wrong with the text. The tree carries the text and where each
 * of its nodes was written; the diagnostics are about the reading of it, so
 * they are kept beside the tree rather than on it.
 */
final readonly class AnalysisResult
{
    /**
     * @param list<Diagnostic> $diagnostics Every error found, in the order
     *   it was found.
     */
    public function __construct(
        public WarrantSyntax $syntax,
        public array $diagnostics = [],
    ) {
    }
}
