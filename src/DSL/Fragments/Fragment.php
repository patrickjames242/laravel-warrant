<?php

namespace Warrant\DSL\Fragments;

use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxProducerKind;

/**
 * One piece of Warrant rule text inside a file, and where each of its bytes came
 * from in that file.
 *
 * A `.warrant` file is one fragment, its text the whole file. A PHP string is
 * not its contents as written: escapes stand for single characters, and a
 * heredoc's closing indentation is taken off every line. So the text is mapped
 * to the file in runs, each a stretch of the text copied unchanged from one
 * stretch of the file.
 */
final readonly class Fragment
{
    /**
     * @param string $text The rule text, as PHP reads it.
     * @param list<array{0: int, 1: int, 2: int}> $runs Each stretch of $text
     *   copied unchanged from the file, in order: its offset in $text, its offset
     *   in the file, and its length.
     * @param string|null $schemaKey The schema rules with no `for` header in the
     *   text are about, or null when that is not known.
     * @param int $fileStart Byte offset in the file where the text's contents start.
     * @param int $fileEnd Byte offset in the file just past where they end.
     * @param SyntaxProducerKind|null $answers What the text is the answer of, when
     *   it is written in a `#[RuleTemplate]` or `#[DerivedCondition]` method:
     *   {@see SyntaxProducerKind::RuleTemplate} or
     *   {@see SyntaxProducerKind::DerivedCondition}. Null for any other text.
     * @param string|null $className The class of that method.
     * @param string|null $methodName That method.
     */
    public function __construct(
        public string $text,
        public array $runs,
        public ?string $schemaKey,
        public int $fileStart,
        public int $fileEnd,
        public ?SyntaxProducerKind $answers = null,
        public ?string $className = null,
        public ?string $methodName = null,
    ) {
    }

    /**
     * The fragment that is all of $text, as a `.warrant` file is.
     *
     * @param string $text The whole file.
     * @param string|null $schemaKey The schema it is about, when known.
     */
    public static function whole(string $text, ?string $schemaKey = null): self
    {
        return new self($text, [[0, 0, strlen($text)]], $schemaKey, 0, strlen($text));
    }

    /**
     * This fragment, about $schemaKey.
     *
     * @param string|null $schemaKey The schema rules with no `for` header are about.
     */
    public function about(?string $schemaKey): self
    {
        return new self($this->text, $this->runs, $schemaKey, $this->fileStart, $this->fileEnd, $this->answers, $this->className, $this->methodName);
    }

    /**
     * This fragment, as the answer of the `#[RuleTemplate]` or
     * `#[DerivedCondition]` method $methodName of $className.
     *
     * @param SyntaxProducerKind $kind What it answers as.
     * @param string $className The method's class.
     * @param string $methodName The method.
     */
    public function answering(SyntaxProducerKind $kind, string $className, string $methodName): self
    {
        return new self($this->text, $this->runs, $this->schemaKey, $this->fileStart, $this->fileEnd, $kind, $className, $methodName);
    }

    /**
     * What produced the text, as the editor reads it from $path: the template or
     * derived condition that answers with it, when it is written in one, and
     * otherwise the editor itself.
     *
     * @param string $path The file the fragment is in.
     */
    public function producer(string $path): SyntaxProducer
    {
        return $this->answers === null || $this->className === null || $this->methodName === null
            ? SyntaxProducer::editor($path, $this->schemaKey)
            : SyntaxProducer::editorAnswer($this->answers, $path, $this->schemaKey, $this->className, $this->methodName);
    }

    /**
     * The byte offset in the file of byte $offset of the text.
     *
     * An offset where one run ends and the next begins is two places in the
     * file when something was left out between them, such as a heredoc line's
     * indentation. The start of a range is placed at the start of the next run,
     * and the end of a range at the end of the run before.
     *
     * @param int $offset 0-based byte offset into the text.
     * @param bool $isEnd Whether the offset ends a range.
     */
    public function fileOffsetOf(int $offset, bool $isEnd = false): int
    {
        $offset = max(0, min($offset, strlen($this->text)));
        $found = null;

        foreach ($this->runs as [$textStart, $fileStart, $length]) {
            if ($offset < $textStart || $offset > $textStart + $length) {
                continue;
            }

            $found = $fileStart + ($offset - $textStart);

            if ($isEnd || $offset < $textStart + $length) {
                return $found;
            }
        }

        return $found ?? $this->fileEnd;
    }

    /**
     * The byte offset in the text of byte $offset of the file, or null when the
     * file offset is outside the fragment. An offset inside something PHP leaves
     * out of the text, such as indentation or the backslash of an escape, is
     * placed at the next byte of the text.
     *
     * @param int $offset 0-based byte offset into the file.
     */
    public function textOffsetOf(int $offset): ?int
    {
        if ($offset < $this->fileStart || $offset > $this->fileEnd) {
            return null;
        }

        foreach ($this->runs as [$textStart, $fileStart, $length]) {
            if ($offset < $fileStart) {
                return $textStart;
            }

            if ($offset <= $fileStart + $length) {
                return $textStart + ($offset - $fileStart);
            }
        }

        return strlen($this->text);
    }
}
