<?php

namespace Warrant\DSL;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * The seam between a compiled {@see WarrantRuleSet} and the host schema. The
 * compiler only knows how to assemble boolean structure and the deny-overrides
 * formula; emitting a condition's SQL is delegated here.
 *
 * Extends {@see SchemaVocabulary}: the declared abilities and conditions (including
 * whether a condition is a row condition, via {@see SchemaVocabulary::getConditionDefinition})
 * are pure vocabulary, shared with validation — which needs nothing more.
 */
interface ConditionResolver extends SchemaVocabulary
{
    /**
     * The schema's key. Used by the compiler to seed the cross-schema cycle
     * guard with a `(schemaKey, ability)` frame per compiled ability.
     */
    public static function schemaKey(): string;

    /**
     * Apply a condition's predicate to $whereClause (mutating it) and return the
     * builder, OR return a boolean for a condition that decides the outcome
     * outright — a global condition evaluated in PHP, or a row condition handed
     * the row it is judging.
     *
     * @param array<int, mixed> $parameters The resolved DSL arguments.
     * @param array<string, mixed> $context The effective check-time context,
     *   exposed to every condition (regardless of `@context` usage).
     * @param Model|null $targetModel The loaded target row, when the check named a
     *   hydrated one. Reaches a row condition as `$c->model`, which is how it can
     *   answer without SQL; null whenever more than one row is in play.
     */
    public function applyCondition(
        string $conditionKey,
        Authenticatable $user,
        Builder $whereClause,
        ?string $targetSqlId,
        array $parameters,
        array $context = [],
        ?Model $targetModel = null,
    ): Builder|bool;
}
