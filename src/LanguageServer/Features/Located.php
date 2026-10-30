<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\Positions\Span;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Vocabulary\Declaration;
use Warrant\LanguageServer\Vocabulary\IndexedSchema;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;
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
     * The schema the text naming $schemaKey's names at the position was written
     * in, which its conditions and templates are looked up from. A `for` header
     * or `check(...)` naming a schema other than the text's own says the names
     * are that schema's; otherwise it is the global schema that answered with
     * the text, when one did, or the schema the text is about. Null when neither
     * is known.
     *
     * @param VocabularyIndex $vocabulary The schemas known.
     * @param string|null $schemaKey The schema the names at the position belong
     *   to, as the cursor's scope says.
     */
    public function writtenIn(VocabularyIndex $vocabulary, ?string $schemaKey): ?ISchemaId
    {
        if ($schemaKey !== null && $schemaKey !== $this->fragment->fragment->schemaKey) {
            return new KeyedSchemaId($schemaKey);
        }

        return $vocabulary->constructs()->writtenIn($this->fragment->analysis->syntax->source->producer(), $schemaKey);
    }

    /**
     * The condition or template named at the position, and the schema that
     * declares it, looked up as validation looks it up: null when the position
     * is on neither, or no schema it is looked up in declares it.
     *
     * @param VocabularyIndex $vocabulary The schemas known.
     * @return array{0: IndexedSchema, 1: Declaration}|null
     */
    public function declared(VocabularyIndex $vocabulary): ?array
    {
        $name = $this->context->name;
        $writtenIn = $this->writtenIn($vocabulary, $this->context->nameSchemaKey);

        if ($name === null || $writtenIn === null) {
            return null;
        }

        $found = match ($this->context->slot) {
            SlotKind::Condition => $vocabulary->constructs()->condition($name, $writtenIn),
            SlotKind::Template => $vocabulary->constructs()->ruleTemplate($name, $writtenIn),
            default => null,
        };
        $schema = $found === null ? null : $vocabulary->declaring($found);
        $declaration = $this->context->slot === SlotKind::Condition ? $schema?->conditions[$name] ?? null : $schema?->templates[$name] ?? null;

        return $schema === null || $declaration === null ? null : [$schema, $declaration];
    }

    /**
     * The declaration of the condition, template or ability named at the
     * position, or null when it is on none of those, or the name is not
     * declared where it is looked up.
     *
     * @param VocabularyIndex $vocabulary The schemas known.
     */
    public function declaration(VocabularyIndex $vocabulary): ?Declaration
    {
        if ($this->context->slot === SlotKind::Ability) {
            $schema = $this->context->nameSchemaKey === null ? null : $vocabulary->schema($this->context->nameSchemaKey);

            return $schema?->abilities[(string) $this->context->name] ?? null;
        }

        return $this->declared($vocabulary)[1] ?? null;
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
     * The span of the innermost node at the position, or null when it is on
     * none.
     */
    public function span(): ?Span
    {
        $nodes = $this->context->nodes;

        return $nodes === [] ? null : $nodes[array_key_last($nodes)]->location->span;
    }
}
