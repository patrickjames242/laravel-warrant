<?php

namespace Warrant\DSL\Validation;

/**
 * Whether the rules a text holds name the abilities their can/cannot clauses and
 * includes apply to. What produced the text says which they must do: rules
 * placed in a rule set as they are must name theirs, and rules placed by an
 * `@include` must leave theirs to it. A `for` header changes nothing here: it
 * says whose names the rules use, not who names their abilities.
 *
 * Inside an ability block the header names the abilities, whatever the mode.
 *
 * @internal
 */
enum GenericMode
{
    /** Every can/cannot clause and include leaves its abilities off, and no ability block is opened. */
    case RequireGeneric;

    /** Every can/cannot clause and include outside an ability block names its abilities. */
    case RequireNotGeneric;

    /** Either, as long as every can/cannot clause, include and block does the same. */
    case Either;
}
