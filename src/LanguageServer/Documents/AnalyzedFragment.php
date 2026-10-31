<?php

namespace Warrant\LanguageServer\Documents;

use Warrant\DSL\Parsing\AnalysisResult;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Fragments\Fragment;

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

    /**
     * Every name node in the fragment's text that writes $name, in the order they
     * are written.
     *
     * @param string $name The name.
     * @return list<NameNode>
     */
    public function namesWriting(string $name): array
    {
        $found = [];
        $pending = $this->analysis->syntax->children;

        while ($pending !== []) {
            $node = array_pop($pending);

            if ($node instanceof NameNode && $node->name === $name) {
                $found[] = $node;
            }

            array_push($pending, ...$node->children());
        }

        usort($found, static fn (NameNode $a, NameNode $b): int => ($a->location->span->offset ?? 0) <=> ($b->location->span->offset ?? 0));

        return $found;
    }
}
