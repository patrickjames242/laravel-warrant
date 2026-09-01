<?php

namespace Warrant\DSL\Compiling;

use RuntimeException;

/**
 * Thrown when compiling a cross-schema `can(...)` reference re-enters a
 * `(schema, ability)` already on the current compile path — an A→B→A style
 * cycle that would otherwise recurse forever. The message names the offending
 * path, e.g. `timesheets:create → pay_periods:approve → timesheets:create`.
 */
final class CrossSchemaCycleException extends RuntimeException
{
    /**
     * @param list<string> $path The visited frames, each already rendered as
     *   "schemaKey:ability", in order, with the repeated frame appended.
     */
    public static function forPath(array $path): self
    {
        return new self(sprintf(
            'Cross-schema can(...) cycle detected: %s. A can(...) reference must not, directly or transitively, depend on the ability being compiled.',
            implode(' → ', $path),
        ));
    }
}
