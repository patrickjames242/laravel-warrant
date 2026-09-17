<?php

namespace Warrant\Rules;

/**
 * What bounds a chain of `@include` expansions, and what it says when the chain
 * cannot end.
 *
 * Expansion itself is the same work wherever it is asked for, but who is asking
 * changes what a runaway should report. A compile has descended through ability
 * and check hops to reach this rule set, and an author needs those hops in the
 * message; reachability analysis and denial diagnosis have no such descent, and a
 * chain of template names is the whole story. The trail is that difference, and
 * the only one — {@see RuleTemplateExpander} is otherwise identical for all three.
 *
 * Implementations are immutable, so a trail is path-scoped for free: a descent
 * made down one include can never leak into the one beside it, because each
 * derives its own and the caller keeps the trail it had. This mirrors
 * {@see \Warrant\DSL\Compiling\CallStack}, which a compiling trail wraps.
 */
interface IncludeTrail
{
    /**
     * The trail one level deeper, or a throw when this descent may not be made.
     */
    public function entering(IncludeInvocation $include): static;
}
