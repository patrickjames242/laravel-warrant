<?php

namespace Warrant\DSL\Validation;

/**
 * The declared names a misspelt one could have meant, as both a validation
 * error's hint and the editor's quick fix offer them, so the two agree.
 */
final class ClosestNames
{
    /** The most names offered for one misspelling. */
    private const MOST = 3;

    /**
     * The names among $candidates close enough to $written to be what was meant,
     * closest first: within a third of its length in edits, and at least two.
     *
     * @param string $written The name as written.
     * @param iterable<string> $candidates The declared names.
     * @return list<string>
     */
    public static function to(string $written, iterable $candidates): array
    {
        $allowed = max(2, intdiv(strlen($written), 3));
        $distances = [];

        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            $distance = levenshtein(strtolower($written), strtolower($candidate));

            if ($distance > 0 && $distance <= $allowed) {
                $distances[$candidate] = $distance;
            }
        }

        asort($distances);

        return array_slice(array_map('strval', array_keys($distances)), 0, self::MOST);
    }
}
