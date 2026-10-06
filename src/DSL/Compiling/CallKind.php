<?php

namespace Warrant\DSL\Compiling;

/**
 * What kind of layer a {@see Call} on the {@see CallStack} represents.
 *
 * The kind is not bookkeeping: it decides whether a call participates in cycle
 * detection. An {@see self::Ability} takes no arguments, so the same
 * `(schema, ability)` twice on one stack is the same work over again and proves
 * non-termination — those are rejected on sight. A {@see self::Check} or a
 * {@see self::Condition} may legitimately recur with different arguments, and the
 * compiler does not try to tell those apart, so they are counted against the
 * depth budget and nothing more.
 */
enum CallKind
{
    /** A `can(...)` resolution — an ability compiled under the deny-overrides formula. */
    case Ability;

    /** A cross-schema `check(...)` — another schema's predicate dispatched inline. */
    case Check;

    /** A condition that expanded into a further expression rather than emitting SQL. */
    case Condition;

    /**
     * Whether re-entering this kind of call proves the compile cannot terminate.
     */
    public function isCycleChecked(): bool
    {
        return $this === self::Ability;
    }
}
