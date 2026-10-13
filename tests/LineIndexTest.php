<?php

use Warrant\DSL\Parsing\Positions\LineIndex;
use Warrant\DSL\Parsing\Positions\LinePosition;

/**
 * The position of each offset in $offsets, as [line, character] pairs.
 *
 * @param list<int> $offsets
 * @return list<array{0: int, 1: int}>
 */
function positionsOf(string $source, array $offsets): array
{
    $index = new LineIndex($source);

    return array_map(static function (int $offset) use ($index): array {
        $position = $index->positionOf($offset);

        return [$position->line, $position->character];
    }, $offsets);
}

it('counts lines from 0 and characters from 0 on each line', function () {
    expect(positionsOf("ab\ncd", [0, 1, 2, 3, 4, 5]))
        ->toBe([[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2]]);
});

it('ends a line at a line feed, a carriage return and line feed, or a carriage return', function () {
    expect(positionsOf("a\nb\r\nc\rd", [2, 5, 7]))->toBe([[1, 0], [2, 0], [3, 0]]);
});

it('places an offset between a carriage return and its line feed at the end of the line', function () {
    expect(positionsOf("ab\r\ncd", [3]))->toBe([[0, 2]]);
});

it('starts a line after a line break at the end of the text', function () {
    expect(positionsOf("a\n", [2]))->toBe([[1, 0]]);
});

it('counts a character of the basic multilingual plane as one unit whatever its bytes', function () {
    // é is 2 bytes, € is 3.
    expect(positionsOf('é€x', [0, 2, 5, 6]))->toBe([[0, 0], [0, 1], [0, 2], [0, 3]]);
});

it('counts a character beyond the basic multilingual plane as two units', function () {
    // 😀 is 4 bytes and a surrogate pair.
    expect(positionsOf('😀x', [4, 5]))->toBe([[0, 2], [0, 3]]);
});

it('places an offset inside a character at the character\'s start', function () {
    expect(positionsOf('a😀', [2, 3, 4]))->toBe([[0, 1], [0, 1], [0, 1]]);
});

it('counts each byte that does not begin a valid character as one unit', function () {
    // A lone continuation byte, then a lead byte whose sequence is cut short.
    expect(positionsOf("\x80\xE2\x82x", [1, 2, 3, 4]))->toBe([[0, 1], [0, 2], [0, 3], [0, 4]]);
});

it('reads an offset outside the text as its nearest end', function () {
    expect(positionsOf("ab\nc", [-3, 99]))->toBe([[0, 0], [1, 1]]);
});

it('converts every position back to the offset it came from', function () {
    $source = "for docs {\r\n  # é€😀\n  they can view\rif owner\n";
    $index = new LineIndex($source);

    for ($offset = 0; $offset <= strlen($source); $offset++) {
        $position = $index->positionOf($offset);
        $start = $index->offsetOf($position);

        expect($index->positionOf($start))->toEqual($position)
            ->and($start)->toBeLessThanOrEqual($offset);
    }
});

it('finds the offset of a position', function () {
    $index = new LineIndex("ab\r\né😀c");

    expect($index->offsetOf(new LinePosition(0, 1)))->toBe(1)
        ->and($index->offsetOf(new LinePosition(1, 0)))->toBe(4)
        ->and($index->offsetOf(new LinePosition(1, 1)))->toBe(6)
        ->and($index->offsetOf(new LinePosition(1, 3)))->toBe(10)
        ->and($index->offsetOf(new LinePosition(1, 4)))->toBe(11);
});

it('reads a character past the end of its line as the end of that line', function () {
    expect((new LineIndex("ab\r\ncd"))->offsetOf(new LinePosition(0, 9)))->toBe(2);
});

it('reads a line past the last as the end of the text', function () {
    expect((new LineIndex("ab\ncd"))->offsetOf(new LinePosition(5, 0)))->toBe(5);
});

it('reads a character between the halves of a surrogate pair as the start of the pair', function () {
    expect((new LineIndex('a😀b'))->offsetOf(new LinePosition(0, 2)))->toBe(1);
});
