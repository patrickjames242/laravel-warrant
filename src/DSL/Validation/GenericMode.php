<?php

namespace Warrant\DSL\Validation;

/**
 * Whether rules with no `for` header name the abilities their clauses and
 * includes apply to. A rule set always names them, so this is about headerless
 * rules alone, and only the caller knows which they must do: rules placed in a
 * rule set as they are must name theirs, and rules placed by an `@include` must
 * leave theirs to it.
 *
 * Inside an ability block the header names the abilities, whatever the mode.
 *
 * @internal
 */
enum GenericMode
{
    /** Every clause and include leaves its abilities off, and no ability block is opened. */
    case RequireGeneric;

    /** Every clause and include outside an ability block names its abilities. */
    case RequireNotGeneric;

    /** Either, as long as every clause, include and block does the same. */
    case Either;
}
