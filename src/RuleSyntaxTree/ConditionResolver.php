<?php

namespace Warrant\RuleSyntaxTree;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;

/**
 * The seam between a compiled {@see WarrantRuleSet} and the host schema. The
 * compiler only knows how to assemble boolean structure and the deny-overrides
 * formula; everything condition-specific (whether a condition is a row condition,
 * and the SQL it emits) is delegated here.
 *
 * Extends {@see SchemaVocabulary}: the declared ability list and name existence
 * are pure vocabulary, shared with validation — which needs nothing more.
 */
interface ConditionResolver extends SchemaVocabulary
{
    /**
     * Whether the keyed condition is a row condition (needs a target SQL id). A
     * row condition is forced to false when compiled without a target.
     */
    public function conditionIsRow(string $conditionKey): bool;

    /**
     * Apply a condition's predicate to $whereClause (mutating it) and return the
     * builder, OR return a boolean for conditions that decide the outcome
     * outright (a true/false global condition).
     *
     * @param array<int, mixed> $parameters The resolved DSL arguments.
     * @param array<string, mixed> $context The effective check-time context,
     *   exposed to every condition (regardless of `@context` usage).
     */
    public function applyCondition(
        string $conditionKey,
        Authenticatable $user,
        Builder $whereClause,
        ?string $targetSqlId,
        array $parameters,
        array $context = [],
    ): Builder|bool;
}
