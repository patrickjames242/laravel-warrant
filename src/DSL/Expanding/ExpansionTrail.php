<?php

namespace Warrant\DSL\Expanding;

use RuntimeException;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;

/**
 * What bounds a chain of expansions — `@include`s and derived conditions, on one
 * budget — and what it says when the chain cannot end.
 *
 * It counts, and it remembers each step so a runaway can name the chain. It does
 * not reject a template or condition for appearing twice: one that recurs with an
 * argument that decreases per level terminates legitimately, and refusing on the
 * name alone would ban exactly those.
 *
 * The trail is immutable, so it is path-scoped for free: a descent made down one
 * branch can never leak into the one beside it, because each derives its own and
 * the caller keeps the trail it had.
 */
final readonly class ExpansionTrail
{
    /**
     * Hard cap on nested expansions.
     *
     * A template or derived condition whose arguments are identical at every
     * level cannot terminate — expansion reads no row and no context, so there is
     * no data-dependent base case to reach — and this is what stops it.
     */
    public const MAX_DEPTH = 64;

    /**
     * @param list<string> $steps The expansions in progress, outermost first, as
     *   they read in an error.
     */
    private function __construct(public array $steps = [])
    {
    }

    /**
     * The empty trail an expansion starts from.
     */
    public static function root(): self
    {
        return new self;
    }

    /**
     * The trail one level deeper for expanding $include.
     */
    public function enteringInclude(IncludeInvocationNode $include): self
    {
        return $this->entering("@include {$include->templateKey}");
    }

    /**
     * The trail one level deeper for expanding the derived condition $conditionKey
     * of the schema keyed $schemaKey.
     */
    public function enteringDerivedCondition(string $schemaKey, string $conditionKey): self
    {
        return $this->entering("{$schemaKey}.{$conditionKey}");
    }

    private function entering(string $step): self
    {
        $deeper = [...$this->steps, $step];

        if (count($deeper) > self::MAX_DEPTH) {
            throw new RuntimeException(sprintf(
                "Expansion exceeded the maximum nesting depth of %d.\n\n"
                    ."Expansion chain (outermost first):\n  %s\n\n"
                    .'Expansion reads no row and no context, so a template or derived condition that expands into '
                    .'itself with the same arguments at every level has no base case to reach. Bound it with an '
                    .'argument that decreases per level.',
                self::MAX_DEPTH,
                implode("\n  ", self::collapseRuns($deeper)),
            ));
        }

        return new self($deeper);
    }

    /**
     * The steps with each run of one step repeated back to back written once, so
     * a runaway reads as the few steps that loop rather than as sixty-five lines
     * of the same one.
     *
     * @param list<string> $steps
     * @return list<string>
     */
    private static function collapseRuns(array $steps): array
    {
        $lines = [];
        $count = 0;

        foreach ($steps as $index => $step) {
            $count++;

            if (($steps[$index + 1] ?? null) === $step) {
                continue;
            }

            $lines[] = $count === 1 ? $step : "{$step}  (repeated {$count} times)";
            $count = 0;
        }

        return $lines;
    }
}
