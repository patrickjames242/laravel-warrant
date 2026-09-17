<?php

namespace Warrant\Rules;

use RuntimeException;

/**
 * The {@see IncludeTrail} for an expansion asked for outside a compile:
 * reachability analysis and denial diagnosis, which reach a rule set straight
 * from the resolver and have no call stack to hang an include on.
 *
 * It counts, and it remembers the template keys so a runaway can name the chain.
 * It does not reject a template for appearing twice: one that recurs with an
 * argument that decreases per level terminates legitimately, and refusing on the
 * name alone would ban exactly those.
 */
final readonly class DepthTrail implements IncludeTrail
{
    /**
     * Hard cap on nested expansions.
     *
     * A template whose arguments are identical at every level cannot terminate —
     * expansion reads no row and no context, so there is no data-dependent base
     * case to reach — and this is what stops it.
     */
    public const MAX_DEPTH = 64;

    /**
     * @param list<string> $templateKeys The templates being expanded, outermost
     *   first.
     */
    private function __construct(public array $templateKeys = [])
    {
    }

    /**
     * The empty trail an expansion starts from.
     */
    public static function root(): self
    {
        return new self;
    }

    public function entering(IncludeInvocation $include): static
    {
        $deeper = [...$this->templateKeys, $include->templateKey];

        if (count($deeper) > self::MAX_DEPTH) {
            throw new RuntimeException(sprintf(
                "Rule template expansion exceeded the maximum nesting depth of %d.\n\n"
                    ."Include chain (outermost first):\n  %s\n\n"
                    .'Expansion reads no row and no context, so a template that includes itself with the same '
                    .'arguments at every level has no base case to reach. Bound it with an argument that '
                    .'decreases per level.',
                self::MAX_DEPTH,
                implode("\n  ", $deeper),
            ));
        }

        return new self($deeper);
    }
}
