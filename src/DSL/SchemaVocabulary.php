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
     * The definition for a single ability, or null if the schema declares no such
     * ability.
     */
    public function getAbilityDefinition(string $abilityKey): ?AbilityDefinition;

    /**
     * The definition for a single condition, or null if the schema declares no such
     * condition.
     */
    public function getConditionDefinition(string $conditionKey): ?ConditionDefinition;
}
