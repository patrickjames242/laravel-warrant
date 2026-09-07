<?php

namespace Warrant\DSL\Compiling;

use RuntimeException;

/**
 * Thrown when a compile re-enters a `(schema, ability)` already on the
 * {@see CallStack} — an A→B→A cycle that would otherwise recurse forever.
 *
 * An ability takes no arguments, so re-entering one is redoing work already in
 * progress and can never terminate; this is a structural error, detected at the
 * earliest possible moment rather than left to the depth budget.
 *
 * The message carries the whole call stack, not just the ability hops. The
 * conditions and `check(...)` layers between them are invisible in an author's
 * rule strings and are usually where the reference that closed the loop actually
 * lives.
 */
final class CrossSchemaCycleException extends RuntimeException
{
    /** @param list<Call> $calls */
    private function __construct(string $message, private readonly array $calls)
    {
        parent::__construct($message);
    }

    /**
     * @param list<Call> $calls The stack including the re-entered call, appended last.
     * @param int $cycleAt Index of the earlier call it re-enters.
     */
    public static function at(array $calls, int $cycleAt): self
    {
        $repeated = $calls[array_key_last($calls)];

        return new self(
            sprintf(
                "Cross-schema can(...) cycle detected: [%s] re-entered.\n\n"
                    ."Compile call stack (most recent last):\n%s\n\n"
                    .'A can(...) reference must not, directly or transitively, depend on the ability being compiled.',
                $repeated->signature(),
                CallStackTrace::render($calls, $cycleAt),
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
