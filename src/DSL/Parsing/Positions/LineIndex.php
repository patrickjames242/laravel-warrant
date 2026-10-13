<?php

namespace Warrant\DSL\Parsing\Positions;

use Generator;

/**
 * Converts between the byte offsets the parser reports and the line positions an
 * editor sends and expects, for one text.
 *
 * A line ends at "\n", "\r\n" or "\r", as the Language Server Protocol counts
 * them. A character is a UTF-16 code unit: one for each character of the Basic
 * Multilingual Plane, two for each beyond it. A byte that does not begin a valid
 * UTF-8 sequence counts as one character, as an editor shows it as one
 * replacement character.
 */
final class LineIndex
{
    /**
     * The byte offset where each line starts, in order; the first is 0.
     *
     * @var list<int>
     */
    private readonly array $lineStarts;

    /**
     * @param string $source The text whose offsets and positions are converted.
     */
    public function __construct(private readonly string $source)
    {
        $lineStarts = [0];

        preg_match_all('/\r\n|\n|\r/', $source, $breaks, PREG_OFFSET_CAPTURE);

        foreach ($breaks[0] as [$break, $offset]) {
            $lineStarts[] = $offset + strlen($break);
        }

        $this->lineStarts = $lineStarts;
    }

    /**
     * The line position of byte $offset. An offset past the end of the text is
     * read as its end, and one inside a character as that character's start.
     *
     * @param int $offset 0-based byte offset into the text.
     */
    public function positionOf(int $offset): LinePosition
    {
        $offset = max(0, min($offset, strlen($this->source)));
        $line = $this->lineHolding($offset);
        $character = 0;

        foreach ($this->charactersOf($line) as [$start, $width, $units]) {
            if ($start + $width > $offset) {
                break;
            }

            $character += $units;
        }

        return new LinePosition($line, $character);
    }

    /**
     * The byte offset of $position. A line past the last is read as the end of
     * the text, a character past the end of its line as the end of that line,
     * and a character between the two halves of a surrogate pair as the start
     * of the pair.
     *
     * @param LinePosition $position The place an editor names.
     */
    public function offsetOf(LinePosition $position): int
    {
        if ($position->line >= count($this->lineStarts)) {
            return strlen($this->source);
        }

        $line = max(0, $position->line);
        $offset = $this->lineStarts[$line];
        $character = 0;

        foreach ($this->charactersOf($line) as [$start, $width, $units]) {
            if ($character + $units > $position->character) {
                return $start;
            }

            $character += $units;
            $offset = $start + $width;
        }

        return $offset;
    }

    /**
     * The line byte $offset is on: the last whose start is at or before it.
     *
     * @param int $offset 0-based byte offset no greater than the text's length.
     */
    private function lineHolding(int $offset): int
    {
        $low = 0;
        $high = count($this->lineStarts) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->lineStarts[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    /**
     * Each character of $line, without the line break ending it, as its byte
     * offset, its width in bytes, and its width in UTF-16 code units.
     *
     * @param int $line 0-based line number of a line in the text.
     * @return Generator<int, array{0: int, 1: int, 2: int}>
     */
    private function charactersOf(int $line): Generator
    {
        $offset = $this->lineStarts[$line];
        $end = $this->lineStarts[$line + 1] ?? strlen($this->source);

        while ($end > $offset && ($this->source[$end - 1] === "\n" || $this->source[$end - 1] === "\r")) {
            $end--;
        }

        while ($offset < $end) {
            $width = $this->widthOfCharacterAt($offset, $end);

            yield [$offset, $width, $width === 4 ? 2 : 1];

            $offset += $width;
        }
    }

    /**
     * How many bytes the UTF-8 character at $offset takes: its sequence's length
     * when the sequence is whole and valid before $end, otherwise 1.
     *
     * @param int $offset 0-based byte offset of the character's first byte.
     * @param int $end 0-based byte offset the sequence may not reach past.
     */
    private function widthOfCharacterAt(int $offset, int $end): int
    {
        $lead = ord($this->source[$offset]);

        $width = match (true) {
            $lead < 0x80 => 1,
            $lead >= 0xC2 && $lead <= 0xDF => 2,
            $lead >= 0xE0 && $lead <= 0xEF => 3,
            $lead >= 0xF0 && $lead <= 0xF4 => 4,
            default => 1,
        };

        if ($width === 1 || $offset + $width > $end) {
            return 1;
        }

        return preg_match('//u', substr($this->source, $offset, $width)) === 1 ? $width : 1;
    }
}
