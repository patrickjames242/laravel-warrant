<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

/**
 * Whether the clauses and includes in a rule body name the abilities they apply
 * to, and why, which depends on where the body is written.
 */
enum AbilityNaming
{
    /**
     * A `for <schema>` body. It is a rule set, and outside an ability block
     * nothing names abilities on a clause's behalf.
     */
    case Required;

    /**
     * An ability block's body: the header is the one place the abilities are
     * said.
     */
    case Forbidden;

    /**
     * Rules written with no `for` header, whose first clause, include or
     * ability block names its abilities, as a provider's rules do. The rest of
     * the text must name theirs too.
     */
    case FirstEntryNamesThem;

    /**
     * Rules written with no `for` header, whose first clause or include leaves
     * its abilities to be named where the rules are placed, as a rule template's
     * body does. The rest of the text must leave theirs too.
     */
    case FirstEntryNamesNone;

    /**
     * The naming of rules with no `for` header whose first entry does, or does
     * not, name its abilities.
     */
    public static function likeFirstEntry(bool $names): self
    {
        return $names ? self::FirstEntryNamesThem : self::FirstEntryNamesNone;
    }

    /**
     * Whether the clauses and includes in the body name their abilities.
     */
    public function names(): bool
    {
        return $this === self::Required || $this === self::FirstEntryNamesThem;
    }

    /**
     * Whether the body is rules with no `for` header, held to their first entry.
     */
    public function followsFirstEntry(): bool
    {
        return $this === self::FirstEntryNamesThem || $this === self::FirstEntryNamesNone;
    }
}
