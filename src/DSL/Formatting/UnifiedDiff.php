<?php

namespace Warrant\DSL\Formatting;

/**
 * What changed between two texts, as a unified diff: `---` and `+++` headers,
 * then each run of changed lines with three unchanged lines either side.
 *
 * The lines both texts start and end with are set aside before the rest is
 * compared, since formatting leaves most of a file as it is.
 */
final class UnifiedDiff
{
    /** The unchanged lines shown either side of a change. */
    private const CONTEXT = 3;

    /**
     * The diff from $before to $after of the file at $path; empty when they are
     * the same.
     */
    public static function of(string $before, string $after, string $path): string
    {
        if ($before === $after) {
            return '';
        }

        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $operations = self::operations($old, $new);
        $diff = "--- a/{$path}\n+++ b/{$path}\n";

        foreach (self::hunks($operations) as [$start, $end]) {
            $oldStart = $operations[$start][1];
            $newStart = $operations[$start][2];
            $oldCount = $newCount = 0;
            $body = '';

            for ($i = $start; $i <= $end; $i++) {
                [$kind, , , $line] = $operations[$i];
                $body .= $kind.$line."\n";
                $oldCount += $kind === '+' ? 0 : 1;
                $newCount += $kind === '-' ? 0 : 1;
            }

            $diff .= sprintf("@@ -%d,%d +%d,%d @@\n", $oldStart, $oldCount, $newStart, $newCount).$body;
        }

        return $diff;
    }

    /**
     * Each line of either text in order: ' ' kept, '-' removed or '+' added,
     * with the line number it has in the old text and in the new one.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    private static function operations(array $old, array $new): array
    {
        $prefix = 0;
        while ($prefix < count($old) && $prefix < count($new) && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < count($old) - $prefix && $suffix < count($new) - $prefix
            && $old[count($old) - 1 - $suffix] === $new[count($new) - 1 - $suffix]) {
            $suffix++;
        }

        $oldMiddle = array_slice($old, $prefix, count($old) - $prefix - $suffix);
        $newMiddle = array_slice($new, $prefix, count($new) - $prefix - $suffix);
        $operations = [];
        $o = $n = 1;

        foreach (array_slice($old, 0, $prefix) as $line) {
            $operations[] = [' ', $o++, $n++, $line];
        }

        foreach (self::middle($oldMiddle, $newMiddle) as [$kind, $line]) {
            $operations[] = [$kind, $o, $n, $line];
            $o += $kind === '+' ? 0 : 1;
            $n += $kind === '-' ? 0 : 1;
        }

        foreach (array_slice($old, count($old) - $suffix) as $line) {
            $operations[] = [' ', $o++, $n++, $line];
        }

        return $operations;
    }

    /**
     * The lines of $old and $new matched by their longest common subsequence.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{0: string, 1: string}>
     */
    private static function middle(array $old, array $new): array
    {
        $rows = count($old);
        $columns = count($new);
        $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = $j = 0;

        while ($i < $rows && $j < $columns) {
            if ($old[$i] === $new[$j]) {
                $lines[] = [' ', $old[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $lines[] = ['-', $old[$i++]];
            } else {
                $lines[] = ['+', $new[$j++]];
            }
        }

        while ($i < $rows) {
            $lines[] = ['-', $old[$i++]];
        }

        while ($j < $columns) {
            $lines[] = ['+', $new[$j++]];
        }

        return $lines;
    }

    /**
     * The first and last index of each hunk: every change, with up to three
     * unchanged lines either side, changes that close joined into one.
     *
     * @param list<array{0: string, 1: int, 2: int, 3: string}> $operations
     * @return list<array{0: int, 1: int}>
     */
    private static function hunks(array $operations): array
    {
        $hunks = [];

        foreach ($operations as $index => [$kind]) {
            if ($kind === ' ') {
                continue;
            }

            $start = max(0, $index - self::CONTEXT);
            $end = min(count($operations) - 1, $index + self::CONTEXT);
            $last = array_key_last($hunks);

            if ($last !== null && $start <= $hunks[$last][1] + 1) {
                $hunks[$last][1] = max($hunks[$last][1], $end);
            } else {
                $hunks[] = [$start, $end];
            }
        }

        return $hunks;
    }
}
