<?php

namespace Warrant\RuleSyntaxTree;

/**
 * A schema's declared vocabulary: the ability names and condition keys a rule
 * string may reference. This is the minimal contract needed to *validate* a
 * rule set — nothing here emits SQL. The compile-time seam
 * {@see ConditionResolver} extends it with the emission methods.
 */
interface SchemaVocabulary
{
    /**
     * The names of the schema's `#[Ability]` abilities. Used to expand `*` and to
     * validate the ability names a rule string may reference.
     *
     * @return array<int, string>
     */
    public static function abilityNames(): array;

    /**
     * Whether a condition with this key is declared by the schema.
     */
    public function conditionExists(string $conditionKey): bool;

    /**
     * The number of DSL arguments the keyed condition requires — its parameters
     * after the leading context object that have no default value. A rule that
     * supplies fewer arguments than this is rejected during validation. Returns 0
     * for an unknown key (its existence is reported by {@see conditionExists}).
     */
    public function requiredConditionArgumentCount(string $conditionKey): int;
}
