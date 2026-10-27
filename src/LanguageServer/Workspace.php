<?php

namespace Warrant\LanguageServer;

use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;

/**
 * What the server knows at a moment: the project, the documents open in the
 * editor, and the schemas rule text is checked against.
 */
final class Workspace
{
    /** @var array<string, Document> By URI. */
    private array $documents = [];

    /**
     * Which reading of the vocabulary {@see $vocabulary} is. Each new reading has
     * a new number, so a document analysed against an older one is analysed again.
     */
    private int $generation = 0;

    /**
     * @param string|null $root Absolute path of the project, or null when the
     *   editor named none.
     * @param VocabularyMode $mode How the schemas are learned.
     * @param VocabularyIndex $vocabulary The schemas known.
     */
    public function __construct(
        public readonly ?string $root = null,
        public readonly VocabularyMode $mode = VocabularyMode::Static,
        private VocabularyIndex $vocabulary = new VocabularyIndex,
    ) {
    }

    public function vocabulary(): VocabularyIndex
    {
        return $this->vocabulary;
    }

    /**
     * Replace the schemas known, so every document is analysed against them.
     *
     * @param VocabularyIndex $vocabulary The new reading.
     */
    public function useVocabulary(VocabularyIndex $vocabulary): void
    {
        $this->vocabulary = $vocabulary;
        $this->generation++;
    }

    /**
     * The catalog rule text is checked against: none when names are not checked,
     * or when no schema could be read, so that a project whose schemas cannot be
     * found gets its syntax checked rather than every name reported unknown.
     */
    public function catalog(): ?VocabularyIndex
    {
        return $this->mode === VocabularyMode::Off || $this->vocabulary->isEmpty() ? null : $this->vocabulary;
    }

    /**
     * Keep $document as the editor has it, in place of an earlier version of it.
     *
     * @param Document $document The document.
     */
    public function open(Document $document): void
    {
        $this->documents[$document->uri] = $document;
    }

    /**
     * @param string $uri The URI of a document the editor has closed.
     */
    public function close(string $uri): void
    {
        unset($this->documents[$uri]);
    }

    /**
     * @param string $uri A document's URI.
     */
    public function document(string $uri): ?Document
    {
        return $this->documents[$uri] ?? null;
    }

    /**
     * @return array<string, Document> By URI.
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * The fragments of rule text in $document, analysed against the schemas known.
     *
     * @param Document $document An open document.
     * @return list<AnalyzedFragment>
     */
    public function analyze(Document $document): array
    {
        return $document->analyzed($this->catalog(), $this->generation, $this->root);
    }
}
