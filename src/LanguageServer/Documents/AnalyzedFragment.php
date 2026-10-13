<?php

namespace Warrant\LanguageServer\Documents;

use Warrant\DSL\Parsing\AnalysisResult;
use Warrant\LanguageServer\Fragments\Fragment;

/**
 * One fragment of a document, and what analysing its rule text found.
 */
final readonly class AnalyzedFragment
{
    /**
     * @param Fragment $fragment The rule text and where it is in the document.
     * @param AnalysisResult $analysis Its tree, diagnostics and tokens.
     */
    public function __construct(
        public Fragment $fragment,
        public AnalysisResult $analysis,
    ) {
    }
}
