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
     * The Eloquent model backing this schema, or `''` for a capability schema
     * that has no rows at all.
     *
     * The compiler reads it to settle one question: whether this schema has rows
     * at all. A capability schema does not, so a compile against one is never
     * targeted however the caller asked for it. Nothing about a row's SQL
     * identity comes from here — {@see \Warrant\Schema\Concerns\ResolvesConditions}
     * derives the table and key column from the same model itself when it builds a
     * {@see \Warrant\Schema\Conditions\RowConditionContext}.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>|''
     */
    public static function modelClass(): string;

    /**
     * Apply a condition's predicate to $whereClause (mutating it) and return the
     * builder, OR return a boolean for a condition that decides the outcome
     * outright — a global condition evaluated in PHP, or a row condition handed
     * the row it is judging.
     *
     * @param bool $targeted Whether a target row is in scope. A row condition
     *   needs one and is rejected without it; the row's SQL identity is the
     *   resolver's own to derive, so it is not passed in.
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
        bool $targeted,
        array $parameters,
        array $context = [],
        ?Model $targetModel = null,
    ): Builder|bool;
}
