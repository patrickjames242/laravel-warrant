<?php

namespace Warrant\DSL\Compiling;

use RuntimeException;

/**
 * Thrown when a compile nests deeper than {@see CallStack::MAX_DEPTH}.
 *
 * This is the guard for the recursions no identity check can decide: a condition
 * that expands into an expression naming further conditions, possibly through
 * other schemas, possibly with different arguments at each level. The compiler
 * does not try to work out which of those terminate — it bounds them.
 *
 * The message reports a repeating segment when the stack ends in one, without the
 * engine having enforced anything against it. That is the common case and the one
 * an author can act on: the same calls with the same arguments at every level
 * cannot terminate, because compilation never consults a row and so never reaches
 * a data-dependent base case.
 */
final class CompileDepthException extends RuntimeException
{
    /** @param list<Call> $calls */
    private function __construct(string $message, private readonly array $calls)
    {
        parent::__construct($message);
    }

    /** @param list<Call> $calls The stack including the call that exceeded the cap. */
    public static function forStack(array $calls, int $max): self
    {
        $explanation = CallStackTrace::endsInRepeat($calls)
            ? 'The repeated segment takes identical arguments at every level, so it cannot terminate: '
                .'compilation never reads a row, so a data-dependent recursion has no base case to reach. '
                .'Bound it with a literal argument that decreases per level, or express the hierarchy with '
                .'a recursive CTE.'
            : 'Nothing repeats on this stack, so this is genuine depth rather than a loop; '
                .'flatten the chain or raise the cap.';

        return new self(
            sprintf(
                "Warrant compilation exceeded the maximum nesting depth of %d.\n\n"
                    ."Compile call stack (most recent last):\n%s\n\n%s",
                $max,
                CallStackTrace::render($calls),
                $explanation,
            ),
            $calls,
        );
    }

    /**
     * The stack as structured data, for a caller that wants to inspect or log it
     * rather than read the message.
     *
     * @return list<Call>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
