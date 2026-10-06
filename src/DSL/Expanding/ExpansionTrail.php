<?php

namespace Warrant\DSL\Expanding;

use RuntimeException;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;

/**
 * What bounds a chain of `@include` expansions, and what it says when the chain
 * cannot end.
 *
 * It counts, and it remembers the template keys so a runaway can name the chain.
 * It does not reject a template for appearing twice: one that recurs with an
 * argument that decreases per level terminates legitimately, and refusing on the
 * name alone would ban exactly those.
 *
 * The trail is immutable, so it is path-scoped for free: a descent made down one
 * include can never leak into the one beside it, because each derives its own and
 * the caller keeps the trail it had.
 */
final readonly class ExpansionTrail
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

    /**
     * The trail one level deeper, or a throw when the chain has grown past the
     * bound.
     */
    public function entering(IncludeInvocationNode $include): self
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
