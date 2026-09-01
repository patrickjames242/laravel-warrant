<?php

namespace Warrant;

/**
 * The result of a *structural* reachability analysis of a rule set for one
 * ability — "could this user ever hold it?" answered from the shape of the
 * rules alone, without evaluating a single condition or touching the database.
 *
 * Only unconditionality makes us certain:
 *  - an unconditional `can` is a grant nothing can narrow away;
 *  - an unconditional `cannot` is a deny no row can dodge;
 *  - any *conditional* rule is a "maybe" — whether it fires depends on a
 *    condition we deliberately do not evaluate here.
 *
 * @see \Warrant\DSL\Compiling\ReachabilityAnalyzer for the decision table.
 */
enum Reachability
{
    /** No grant path exists, or an unconditional `cannot` forbids it outright. */
    case NEVER;

    /** A condition decides — the user may or may not have it, depending. */
    case MAYBE;

    /** Unconditionally granted with no unconditional deny — they always have it. */
    case ALWAYS;
}
