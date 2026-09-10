<?php

namespace Warrant\DSL;

use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ConditionDefinition;

/**
 * A schema's declared vocabulary: the abilities and conditions a rule string may
 * reference. This is the minimal contract needed to *validate* a rule set —
 * nothing here emits SQL. The compile-time seam {@see ConditionResolver} extends
 * it with the emission methods.
 *
 * Existence and metadata are answered together: {@see getAbilityDefinition} and
 * {@see getConditionDefinition} return the definition (or null if undeclared), so
 * a caller checks existence and reads what it needs — a condition's row-ness or
 * required argument count — from one lookup.
 */
interface SchemaVocabulary
{
    /**
     * Whether the schema has rows at all, and so whether it answers targeted
     * checks. False for a capability schema, which declares abilities about the
     * user and nothing else.
     */
    public static function hasRows(): bool;

    /**
     * Whether one of the schema's rows can be named, and so whether it answers a
     * check about a single row. False for rows with no key and no way to find one
     * — a virtual table declaring neither — which are still filterable but not
     * addressable.
     */
    public static function hasRowKey(): bool;

    /**
     * The definition for a single ability, or null if the schema declares no such
     * ability.
     */
    public function getAbilityDefinition(string $abilityKey): ?AbilityDefinition;

    /**
     * The definition for a single condition, or null if the schema declares no such
     * condition.
     */
    public function getConditionDefinition(string $conditionKey): ?ConditionDefinition;

    /**
     * The definition of the schema's row key, which every row-bound handle's
     * arguments are bound to. Read for its required argument count, so a handle
     * supplying too few is reported against the rule text.
     */
    public function getKeyDefinition(): ConditionDefinition;
}
