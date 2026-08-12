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
     * Every ability declared by the schema. Used to expand `*` and to validate
     * ability names.
     *
     * @return array<int, string>
     */
    public static function declaredAbilities(): array;

    /**
     * Every check-time context key declared by the schema. Used to validate that
     * a rule's `@context <key>` reference names a declared key.
     *
     * @return array<int, string>
     */
    public static function declaredContextKeys(): array;

    /**
     * Whether a condition with this key is declared by the schema.
     */
    public function conditionExists(string $conditionKey): bool;
}
