<?php

namespace Warrant\LanguageServer\Positions;

/**
 * What a name written in rule text names, and so where an editor looks it up.
 */
enum SlotKind
{
    /** A condition's name: `owner` in `if owner` or `check(owner for docs)`. */
    case Condition;

    /**
     * An ability's name: in a `they can` or `they cannot` clause, an ability
     * block's header, an `@include`'s `for` list, or a `can(...)`.
     */
    case Ability;

    /** A schema's name: after a `for` header's `for`, or in a handle. */
    case Schema;

    /** A context key: after `@context`, or on the left of a `with` map's `=`. */
    case ContextKey;

    /** A column's name, after `@column` and any frame name. */
    case Column;

    /** A rule template's name, after `@include`. */
    case Template;

    /**
     * A frame's name: given to a handle's rows after `as`, or written before the
     * `.` of a `@column` reference to name them.
     */
    case Alias;
}
