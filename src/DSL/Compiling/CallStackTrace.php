<?php

namespace Warrant\DSL\Compiling;

/**
 * Renders a {@see CallStack} as a numbered, human-readable trace for an exception
 * message — the compiler's equivalent of a stack trace, listing the layers an
 * author cannot see in their own rules.
 *
 * Two jobs beyond numbering the lines. It marks the two ends of a detected cycle,
 * so an ability that re-enters itself points at the frame it came back to. And it
 * collapses a repeating tail, because a stack that ran to the depth cap is usually
 * the same few calls sixty times over, and printing them all buries the three
 * lines that explain how the loop was reached.
 */
final class CallStackTrace
{
    /** The longest repeating segment worth looking for when collapsing a trace. */
    private const MAX_PERIOD = 8;

    /** How many times a segment must occur before it is collapsed rather than printed. */
    private const MIN_REPETITIONS = 3;

    /**
     * @param list<Call> $calls
     * @param int|null $cycleAt Index of the call the last one re-enters, when this
     *   trace is describing a detected cycle. Marks both ends and disables
     *   collapsing — a cycle trace stops at the first repeat and is short by
     *   construction.
     */
    public static function render(array $calls, ?int $cycleAt = null): string
    {
        $total = count($calls);
        $width = strlen((string) $total);
        $collapse = $cycleAt === null ? self::repeatingTail($calls) : null;
        $printThrough = $collapse === null ? $total : $collapse['start'] + $collapse['period'];

        $lines = [];

        foreach ($calls as $index => $call) {
            if ($index >= $printThrough) {
                break;
            }

            $line = sprintf('  %s. %s', str_pad((string) ($index + 1), $width, ' ', STR_PAD_LEFT), $call->signature());

            if ($cycleAt !== null && $index === $cycleAt) {
                $line .= '   ← cycle starts here';
            }

            if ($cycleAt !== null && $index === $total - 1) {
                $line .= sprintf('   ← re-enters frame %d', $cycleAt + 1);
            }

            $lines[] = $line;
        }

        if ($collapse !== null) {
            $lines[] = sprintf(
                '     … frames %d–%d repeat this %d-frame segment %d more time%s',
                $printThrough + 1,
                $total,
                $collapse['period'],
                $collapse['repetitions'] - 1,
                $collapse['repetitions'] - 1 === 1 ? '' : 's',
            );
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Whether the stack ends in a segment that repeats identically — the same
     * calls with the same arguments, which can only go on forever.
     *
     * The depth exception uses this to decide whether to say so. A stack that hit
     * the cap without repeating is a different problem (genuinely deep
     * composition), and telling that author about non-terminating recursion would
     * send them looking for a loop that is not there.
     *
     * @param list<Call> $calls
     */
    public static function endsInRepeat(array $calls): bool
    {
        return self::repeatingTail($calls) !== null;
    }

    /**
     * Find the shortest segment the stack ends by repeating.
     *
     * @param list<Call> $calls
     * @return array{period: int, repetitions: int, start: int}|null
     */
    private static function repeatingTail(array $calls): ?array
    {
        $total = count($calls);

        for ($period = 1; $period <= min(self::MAX_PERIOD, intdiv($total, 2)); $period++) {
            $repetitions = 1;

            while (self::segmentRepeats($calls, $period, $repetitions)) {
                $repetitions++;
            }

            if ($repetitions >= self::MIN_REPETITIONS) {
                return [
                    'period' => $period,
                    'repetitions' => $repetitions,
                    'start' => $total - ($repetitions * $period),
                ];
            }
        }

        return null;
    }

    /**
     * Whether the final $period calls also appear $repetitions segments earlier.
     *
     * @param list<Call> $calls
     */
    private static function segmentRepeats(array $calls, int $period, int $repetitions): bool
    {
        $total = count($calls);
        $offset = $repetitions * $period;

        if ($total - $offset - $period < 0) {
            return false;
        }

        for ($i = 0; $i < $period; $i++) {
            if (! $calls[$total - 1 - $i]->repeats($calls[$total - 1 - $i - $offset])) {
                return false;
            }
        }

        return true;
    }
}
