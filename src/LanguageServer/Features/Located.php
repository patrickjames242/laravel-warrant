<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\Positions\Span;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Workspace;

/**
 * An editor position found in a document's rule text: the fragment it is in,
 * the offset in that fragment's text, and what is there.
 */
final readonly class Located
{
    /**
     * @param AnalyzedFragment $fragment The fragment the position is in.
     * @param int $offset The position's byte offset in the fragment's text.
     * @param CursorContext $context What is at that offset.
     */
    public function __construct(
        public AnalyzedFragment $fragment,
        public int $offset,
        public CursorContext $context,
    ) {
    }

    /**
     * Where $position falls in $document's rule text, or null when it falls in
     * none.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{line?: int, character?: int} $position An LSP position.
     */
    public static function at(Workspace $workspace, Document $document, array $position): ?self
    {
        $found = Document::fragmentAt($workspace->analyze($document), $document->offsetAt($position));

        if ($found === null) {
            return null;
        }

        [$fragment, $offset] = $found;

        return new self($fragment, $offset, CursorContext::at(
            $fragment->analysis->syntax,
            $offset,
            $workspace->catalog(),
            $fragment->fragment->schemaKey,
        ));
    }

    /**
     * The range in $document of $span of the fragment's text.
     *
     * @param Document $document The document the fragment is in.
     * @param Span $span A range of the fragment's text.
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    public function rangeOf(Document $document, Span $span): array
    {
        return $document->rangeOf(
            $this->fragment->fragment->fileOffsetOf($span->offset),
            $this->fragment->fragment->fileOffsetOf($span->endOffset, isEnd: true),
        );
    }

    /**
     * The span of the innermost node or part at the position, or null when it
     * is on none.
     */
    public function span(): ?Span
    {
        $entries = $this->context->entries;

        return $entries === [] ? null : $entries[array_key_last($entries)]->span;
    }
}
