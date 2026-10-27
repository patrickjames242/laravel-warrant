<?php

namespace Warrant\DSL\Formatting;

use LogicException;

/**
 * Lines of formatted rule text as they are put together: each line's text, the
 * comment that trails it, and the comments that go on lines of their own before
 * the first.
 *
 * The first line starts wherever it is placed, so it carries no indentation of
 * its own. Every later line is indented relative to the line the first one ends
 * up on, so a piece laid out once can be placed at any depth.
 */
final class LaidOutLines
{
    /** @var list<array{0: string, 1: ?string}> Each line's text and trailing comment. */
    private array $lines;

    /** @var list<string> The comment lines, and blank lines as '', that go before the first line. */
    private array $before = [];

    private function __construct(string $text)
    {
        $this->lines = [[$text, null]];
    }

    /**
     * One line of $text.
     */
    public static function of(string $text): self
    {
        return new self($text);
    }

    public function isOneLine(): bool
    {
        return count($this->lines) === 1;
    }

    /**
     * How wide the last line is, counting its indentation relative to the first.
     */
    public function lastLineWidth(): int
    {
        return mb_strlen($this->lines[array_key_last($this->lines)][0]);
    }

    /**
     * How far the last line is indented relative to the first.
     */
    public function lastLineIndent(): int
    {
        $text = $this->lines[array_key_last($this->lines)][0];

        return strlen($text) - strlen(ltrim($text, ' '));
    }

    public function endsWithComment(): bool
    {
        return $this->lines[array_key_last($this->lines)][1] !== null;
    }

    /**
     * Add $text to the end of the last line, before any comment trailing it.
     */
    public function append(string $text): self
    {
        $this->lines[array_key_last($this->lines)][0] .= $text;

        return $this;
    }

    /**
     * Continue the last line with $other's first line, the rest of $other
     * following at the last line's indentation. The comments $other has before
     * its first line go before the last line.
     */
    public function appendLines(self $other): self
    {
        $last = array_key_last($this->lines);
        $indent = str_repeat(' ', strlen($this->lines[$last][0]) - strlen(ltrim($this->lines[$last][0], ' ')));

        if ($other->before !== []) {
            if ($last === 0) {
                array_push($this->before, ...$other->before);
            } else {
                array_splice($this->lines, $last, 0, array_map(
                    static fn (string $comment): array => [$comment === '' ? '' : $indent.$comment, null],
                    $other->before,
                ));
                $last = array_key_last($this->lines);
            }
        }

        [$text, $comment] = $other->lines[0];

        if ($comment !== null && $this->lines[$last][1] !== null) {
            throw new LogicException('Two comments were laid out on one line.');
        }

        $this->lines[$last][0] .= $text;
        $this->lines[$last][1] ??= $comment;

        foreach (array_slice($other->lines, 1) as [$text, $comment]) {
            $this->lines[] = [$text === '' ? '' : $indent.$text, $comment];
        }

        return $this;
    }

    /**
     * Start a new line with $other, indented $indent spaces past the first line,
     * the comments $other has before its first line going on lines of their own
     * just above it.
     */
    public function addLine(self $other, int $indent): self
    {
        $pad = str_repeat(' ', $indent);

        foreach ($other->before as $comment) {
            $this->lines[] = [$comment === '' ? '' : $pad.$comment, null];
        }

        foreach ($other->lines as [$text, $comment]) {
            $this->lines[] = [$text === '' ? '' : $pad.$text, $comment];
        }

        return $this;
    }

    public function addBlankLine(): self
    {
        $this->lines[] = ['', null];

        return $this;
    }

    /**
     * Put $lines, comments and blank lines given as '', before the first line,
     * ahead of any already there.
     *
     * @param list<string> $lines
     */
    public function lead(array $lines): self
    {
        $this->before = [...$lines, ...$this->before];

        return $this;
    }

    /**
     * Trail the last line with $comment.
     */
    public function trail(string $comment): self
    {
        $last = array_key_last($this->lines);

        if ($this->lines[$last][1] !== null) {
            throw new LogicException('Two comments were laid out on one line.');
        }

        $this->lines[$last][1] = $comment;

        return $this;
    }

    /**
     * The lines as text, joined by line breaks, with no break after the last.
     */
    public function render(): string
    {
        $rendered = [];

        foreach ($this->before as $comment) {
            $rendered[] = $comment;
        }

        foreach ($this->lines as [$text, $comment]) {
            $text = rtrim($text, ' ');
            $rendered[] = $comment === null ? $text : ($text === '' ? $comment : $text.' '.$comment);
        }

        return implode("\n", $rendered);
    }
}
