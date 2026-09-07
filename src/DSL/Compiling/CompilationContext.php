<?php

namespace Warrant\DSL\Compiling;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The state {@see RuleSetCompiler} threads as it walks a boolean expression tree.
 *
 * It bundles the check-time invariants that never change during a single compile
 * (the user, the query factory the leaves are built from, the target row's SQL
 * identity when there is one, the target model when one was supplied, the
 * effective check-time context bag) together with the one piece of
 * position-dependent state a recursive step derives for its children: whether
 * the current subtree is negated, flipped at each `not` so that negation lands
 * on the leaves.
 *
 * The connector a predicate attaches under is not here — the walk builds a
 * {@see \Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode} whose operands each carry
 * their own connector, so position no longer has to be threaded through.
 *
 * Nor is the host query: {@see $queries} is a {@see QueryFactory}, which is all
 * the walk ever wanted from it — a source of fresh builders plus the grammar to
 * quote identifiers with. Carrying it here is what lets every step take one
 * argument instead of a context and a builder side by side.
 *
 * Immutable: {@see negated} returns a modified copy, so a step can derive a
 * child context without disturbing its own.
 */
final readonly class CompilationContext
{
    /**
     * @param string|null $targetSqlId The target row's qualified key, derived by
     *   the compiler from the schema's own model — null in a no-target compile.
     *   Its *nullness* is what the walk reads: a row condition cannot be evaluated
     *   without a row, so {@see RuleSetCompiler::conditionLeaf()} folds one to
     *   `false` rather than emitting a reference to a table that is not in the
     *   query. The string itself is passed on to
     *   {@see \Warrant\DSL\ConditionResolver::applyCondition()} unchanged.
     * @param array<string, mixed> $checkContext The effective check-time context.
     * @param list<string> $visited The `(schema, ability)` frames on the current
     *   cross-schema compile path (each `"schemaClass\0ability"`), for cycle
     *   detection. Framed by class string so no schema-key lookup is needed on the
     *   compile path; {@see RuleSetCompiler::describeFrame()} maps them back to keys
     *   for the cycle message. Path-scoped: a frame added descending into a
     *   referenced schema never leaks to a sibling branch, since each step derives
     *   a fresh copy.
     * @param Model|null $targetModel The loaded row the check was aimed at, when the
     *   caller supplied a hydrated one. Lets a row condition answer in PHP instead of
     *   emitting SQL; null whenever the compile covers more than one row (or none),
     *   which every row condition must still handle by returning its predicate.
     */
    public function __construct(
        public Authenticatable $user,
        public QueryFactory $queries,
        public ?string $targetSqlId,
        public array $checkContext,
        public bool $negate = false,
        public array $visited = [],
        public ?Model $targetModel = null,
    ) {
    }

    /**
     * Derive a copy with the negation flag toggled (crossing a `not`).
     */
    public function negated(): self
    {
        return new self(
            user: $this->user,
            queries: $this->queries,
            targetSqlId: $this->targetSqlId,
            checkContext: $this->checkContext,
            negate: ! $this->negate,
            visited: $this->visited,
            targetModel: $this->targetModel,
        );
    }
}
