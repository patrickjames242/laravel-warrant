<?php

namespace Warrant\LanguageServer\Documents;

use Warrant\DSL\Parsing\ASTNodes\InvalidSyntax;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\DSL\Fragments\FragmentExtractor;
use Warrant\PhpSource\RuleProviderClasses;
use Warrant\LanguageServer\Positions\LineIndex;
use Warrant\LanguageServer\Positions\LinePosition;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;

/**
 * One document open in the editor: its text as the editor has it, and the
 * analysis of the rule text in it, kept until the text or the vocabulary changes.
 */
final class Document
{
    private ?LineIndex $lines = null;

    private ?bool $mayHoldRuleText = null;

    /** @var list<AnalyzedFragment>|null */
    private ?array $analyzed = null;

    /** The vocabulary generation {@see $analyzed} was made with. */
    private int $analyzedWith = -1;

    /**
     * @param string $uri The URI the editor names it by.
     * @param string $path Its path on disk, which says what kind of file it is.
     * @param string $text Its contents.
     * @param int $version The editor's version number for these contents.
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $path,
        public readonly string $text,
        public readonly int $version,
    ) {
    }

    /**
     * The same document with new contents.
     *
     * @param string $text The new contents.
     * @param int $version The editor's version number for them.
     */
    public function withText(string $text, int $version): self
    {
        return new self($this->uri, $this->path, $text, $version);
    }

    /**
     * Whether the document may hold rule text, by {@see FragmentExtractor::mayHoldRuleText()}.
     */
    public function mayHoldRuleText(): bool
    {
        return $this->mayHoldRuleText ??= FragmentExtractor::mayHoldRuleText($this->path, $this->text);
    }

    public function lines(): LineIndex
    {
        return $this->lines ??= new LineIndex($this->text);
    }

    /**
     * The byte offset of an editor position in the document.
     *
     * @param array{line?: int, character?: int} $position An LSP position.
     */
    public function offsetAt(array $position): int
    {
        return $this->lines()->offsetOf(new LinePosition((int) ($position['line'] ?? 0), (int) ($position['character'] ?? 0)));
    }

    /**
     * The LSP position of byte $offset.
     *
     * @param int $offset 0-based byte offset into the document.
     * @return array{line: int, character: int}
     */
    public function positionAt(int $offset): array
    {
        $position = $this->lines()->positionOf($offset);

        return ['line' => $position->line, 'character' => $position->character];
    }

    /**
     * The LSP range from byte $offset to byte $endOffset.
     *
     * @param int $offset 0-based byte offset of the range's start.
     * @param int $endOffset 0-based byte offset just past its end.
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    public function rangeOf(int $offset, int $endOffset): array
    {
        return ['start' => $this->positionAt($offset), 'end' => $this->positionAt($endOffset)];
    }

    /**
     * Every fragment of rule text in the document, analysed against $index.
     * Each is read as the editor's, for the schema the fragment is about, and
     * validated without binding values, which an editor never has.
     *
     * @param VocabularyIndex|null $index The schemas to check names against; null
     *   to check syntax and the rules of the language that need no schema only.
     * @param int $generation Which reading of the vocabulary $index is; a new
     *   one is analysed afresh.
     * @param string|null $projectRoot The project the document is in, whose
     *   classes say which `rules()` methods return rule text.
     * @return list<AnalyzedFragment>
     */
    public function analyzed(?VocabularyIndex $index, int $generation, ?string $projectRoot = null): array
    {
        if ($this->analyzed !== null && $this->analyzedWith === $generation) {
            return $this->analyzed;
        }

        $known = $index ?? new VocabularyIndex;
        $classes = RuleProviderClasses::ofProject($projectRoot)->knowing($this->path, $this->text);
        $fragments = (new FragmentExtractor(
            $known->keyOfClass(...),
            // A class the index knows as a schema provides rules, even where its source cannot be followed.
            static fn (string $class): bool => $known->keyOfClass($class) !== null || $classes->providesRules($class),
        ))->extract($this->path, $this->text);

        $this->analyzed = array_map(
            function ($fragment) use ($index): AnalyzedFragment {
                $analysis = WarrantParser::analyze($fragment->text, $fragment->producer($this->path));
                $checked = ValidSyntax::check($analysis->syntax, new ValidationInput($index, checkBindingValues: false));

                return new AnalyzedFragment(
                    $fragment,
                    $analysis,
                    [...$analysis->diagnostics, ...($checked instanceof InvalidSyntax ? $checked->diagnostics : [])],
                );
            },
            $fragments,
        );
        $this->analyzedWith = $generation;

        return $this->analyzed;
    }

    /**
     * The fragment byte $offset of the document is in, and the offset in its
     * text; null when the offset is in no rule text.
     *
     * @param list<AnalyzedFragment> $analyzed The document's fragments.
     * @param int $offset 0-based byte offset into the document.
     * @return array{0: AnalyzedFragment, 1: int}|null
     */
    public static function fragmentAt(array $analyzed, int $offset): ?array
    {
        foreach ($analyzed as $fragment) {
            $textOffset = $fragment->fragment->textOffsetOf($offset);

            if ($textOffset !== null) {
                return [$fragment, $textOffset];
            }
        }

        return null;
    }
}
