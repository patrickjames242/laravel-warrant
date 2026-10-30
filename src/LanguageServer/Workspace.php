<?php

namespace Warrant\LanguageServer;

use Closure;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Documents\ProjectFiles;
use Warrant\LanguageServer\Documents\Uri;
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
     * Answers worked out from the whole project, by key, kept until anything they
     * may have been worked out from changes: a document, the schemas, or the
     * project's files.
     *
     * @var array<string, mixed>
     */
    private array $remembered = [];

    /**
     * The paths of the project's files that may hold rule text, once listed,
     * kept until {@see forgetProjectFiles()}.
     *
     * @var list<string>|null
     */
    private ?array $projectFiles = null;

    /**
     * The project's files read from disk, by path, each with the modification
     * time it was read at, so a file is read again only once it has changed.
     *
     * @var array<string, array{0: int, 1: Document}>
     */
    private array $read = [];

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
        $this->remembered = [];
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
        $this->remembered = [];
        $this->documents[$document->uri] = $document;
    }

    /**
     * @param string $uri The URI of a document the editor has closed.
     */
    public function close(string $uri): void
    {
        $this->remembered = [];
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
     * Every file of the project whose text holds $text and that may hold rule
     * text: as the editor has it when it is open, and as it is on disk otherwise.
     *
     * @param string $text What the file must hold.
     * @return list<Document>
     */
    public function projectDocumentsHolding(string $text): array
    {
        $open = [];

        foreach ($this->documents as $document) {
            $open[$document->path] = $document;
        }

        $found = [];

        $this->projectFiles ??= $this->root === null ? [] : ProjectFiles::under($this->root);

        foreach ($this->projectFiles as $path) {
            $document = $open[$path] ?? $this->fromDisk($path);
            unset($open[$path]);

            if ($document !== null && self::holds($document, $text)) {
                $found[] = $document;
            }
        }

        foreach ($open as $document) {
            if (self::holds($document, $text)) {
                $found[] = $document;
            }
        }

        return $found;
    }

    private static function holds(Document $document, string $text): bool
    {
        return $document->mayHoldRuleText() && str_contains($document->text, $text);
    }

    /**
     * List the project's files afresh the next time they are searched, since one
     * has been created or deleted.
     */
    public function forgetProjectFiles(): void
    {
        $this->projectFiles = null;
        $this->forgetProjectAnswers();
    }

    /**
     * Work the answers {@see remember()} keeps out afresh, since a file of the
     * project has changed on disk.
     */
    public function forgetProjectAnswers(): void
    {
        $this->remembered = [];
    }

    /**
     * The answer kept under $key, worked out by $answer when none is kept.
     *
     * @template T
     *
     * @param string $key What the answer is to.
     * @param Closure(): T $answer Works it out from the whole project.
     * @return T
     */
    public function remember(string $key, Closure $answer): mixed
    {
        if (! array_key_exists($key, $this->remembered)) {
            $this->remembered[$key] = $answer();
        }

        return $this->remembered[$key];
    }

    /**
     * The file at $path as it is on disk, or null when it cannot be read.
     *
     * @param string $path Absolute path.
     */
    private function fromDisk(string $path): ?Document
    {
        $modified = @filemtime($path);

        if ($modified === false) {
            return null;
        }

        if (($this->read[$path][0] ?? null) !== $modified) {
            $text = @file_get_contents($path);

            if ($text === false) {
                return null;
            }

            $this->read[$path] = [$modified, new Document(Uri::fromPath($path), $path, $text, 0)];
        }

        return $this->read[$path][1];
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
