<?php

namespace Warrant;

/**
 * The result of a reachability analysis of a rule set for one ability — "could
 * this user ever hold it?" answered from the rules alone, with no row, no
 * context and no query.
 *
 * Row and global conditions are never evaluated, so each could answer anything.
 * Everything that can be answered without a row is followed: constants, derived
 * conditions, and the `can(...)` references the rules lean on. An ability only
 * granted alongside another the user can never hold is NEVER, and one granted on
 * another they always hold is ALWAYS.
 *
 * @see \Warrant\DSL\Compiling\ReachabilityAnalyzer for how an ability is judged.
 */
enum Reachability
{
    /** No way the rules can come out grants it. */
    case NEVER;

    /** A condition decides — the user may or may not have it, depending. */
    case MAYBE;

    /** Every way the rules can come out grants it. */
    case ALWAYS;
}
