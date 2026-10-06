<?php

namespace Warrant\DSL;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * The seam between a compiled {@see \Warrant\DSL\Parsing\ASTNodes\RuleSetNode} and the host schema. The
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
     * The query this schema's rows come from, or null when they are a model's
     * table. A schema has one or the other, never both.
     *
     * The compiler selects a hop's subquery from it, so it is read afresh each
     * time rather than held.
     */
    public static function virtualTable(): ?\Illuminate\Database\Query\Builder;

    /**
     * The Eloquent model backing this schema, or `''` for a capability schema
     * that has no rows at all.
     *
     * The compiler reads it for two things: whether this schema has rows at all —
     * a capability schema does not, so a compile against one is never targeted
     * however the caller asked for it — and the default name those rows go by in
     * SQL, for when nothing closer to the query says otherwise (see
     * `$rowQualifier` on {@see applyCondition}).
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>|''
     */
    public static function modelClass(): string;

    /**
     * Dispatch a named row or global condition, which may answer in any of three
     * ways:
     *
     *  - Apply its predicate to $whereClause (mutating it) and return the builder.
     *  - Return a boolean, deciding the outcome outright — a global condition
     *    evaluated in PHP, or a row condition handed the row it is judging.
     *  - Return null, answering *unknown*: the question has no answer here. An
     *    unknown negates to itself, so it neither grants nor lifts a deny. A
     *    condition answering unknown must leave $whereClause untouched — PHP
     *    returns null from a method with no `return` statement, so the builder's
     *    state is what tells a deliberate unknown from a forgotten return, and
     *    doing both is rejected.
     *
     * A derived condition is never dispatched: it is expanded into its expression
     * by {@see \Warrant\DSL\Expanding\RuleSetExpander} before anything compiles.
     *
     * @param bool $targeted Whether a target row is in scope. A row condition
     *   needs one and is rejected without it.
     * @param array<int, mixed> $parameters The resolved DSL arguments.
     * @param array<string, mixed> $context The effective check-time context,
     *   exposed to every condition (regardless of `@context` usage).
     * @param Model|null $targetModel The loaded target row, when the check named a
     *   hydrated one. Reaches a row condition as `$c->model`, which is how it can
     *   answer without SQL; null whenever more than one row is in play.
     * @param string|null $rowQualifier The SQL name the target row answers to where
     *   this predicate will be spliced, which a row condition qualifies its columns
     *   with (`$c->row('owner_id')`). It is *not* always the model's table: the same
     *   rule compiles against `d` for a query the caller wrote as
     *   `from('docs as d')`, and against a hop's alias when reached through
     *   `can(… for docs(…) as d2)`. Null means the compiler had nothing closer to
     *   say than the model, which is then used — so an implementation must fall
     *   back to its own table rather than treat null as an error.
     */
    public function applyCondition(
        string $conditionKey,
        Authenticatable $user,
        Builder $whereClause,
        bool $targeted,
        array $parameters,
        array $context = [],
        ?Model $targetModel = null,
        ?string $rowQualifier = null,
    ): Builder|bool|null;

    /**
     * Narrow $whereClause to the row a handle's arguments name, by dispatching the
     * schema's row key.
     *
     * Answers in one of two ways: the constrained builder, or null for *unknown* —
     * arguments that name no row, such as an absent `@context` value. Unknown is
     * the only safe answer there, because the `exists` this predicate lands in
     * cannot report one once it is built.
     *
     * @param array<int, mixed> $arguments The resolved handle arguments, bound
     *   positionally after the context object.
     * @param array<string, mixed> $context The effective check-time context.
     * @param Model|null $targetModel The loaded row, when the caller named a
     *   hydrated one.
     * @param string|null $rowQualifier The SQL name the row answers to where this
     *   predicate lands; null means the schema model's own table.
     */
    public function applyKey(
        Authenticatable $user,
        Builder $whereClause,
        array $arguments,
        array $context = [],
        ?Model $targetModel = null,
        ?string $rowQualifier = null,
    ): ?Builder;

    /**
     * The context a `can(<ability>)` of this schema compiles under: $context merged
     * over the schema's default context, explicit values winning.
     *
     * Answers null when that context lacks a key the schema requires, or a key
     * $ability requires. The reference then has no answer, which is unknown: it
     * neither grants nor lifts a deny.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function contextForReference(array $context, string $ability): ?array;
}
