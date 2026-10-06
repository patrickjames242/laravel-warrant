<?php

namespace Warrant\DSL\Parsing;

/**
 * Whether the clauses and includes in a rule body name the abilities they apply
 * to, which depends on where the body is written.
 */
enum AbilityNaming
{
    /**
     * A `for <schema>` body. It is a rule set, and outside an ability block
     * nothing names abilities on a clause's behalf.
     */
    case Required;

    /**
     * Rules written with no `for` header. They either all name their abilities,
     * as a provider's rules do, or all leave them to be named where the rules are
     * placed, as a rule template's body does. The first clause, include or
     * ability block decides which, and the rest of the text must agree.
     */
    case Consistent;

    /**
     * An ability block's body: the header is the one place the abilities are
     * said.
     */
    case Forbidden;
}
