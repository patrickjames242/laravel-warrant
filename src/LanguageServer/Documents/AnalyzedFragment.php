<?php

namespace Warrant\LanguageServer\Documents;

use Warrant\DSL\Parsing\AnalysisResult;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\LanguageServer\Fragments\Fragment;

/**
 * One fragment of a document, and what analysing its rule text found.
 */
final readonly class AnalyzedFragment
{
    /**
     * @param Fragment $fragment The rule text and where it is in the document.
     * @param AnalysisResult $analysis Its tree, syntax errors and tokens.
     * @param list<Diagnostic> $diagnostics Every problem in it: the syntax
     *   errors, then the mistakes validating the tree found.
     */
    public function __construct(
        public Fragment $fragment,
        public AnalysisResult $analysis,
        public array $diagnostics,
    ) {
    }
}
