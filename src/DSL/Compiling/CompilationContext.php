<?php

namespace Warrant\DSL\Compiling;

/**
 * The state {@see RuleSetCompiler} threads as it walks a boolean expression tree.
 *
 * It is the {@see CompilationInput} as it stands at this point in the compile,
 * plus the two things the input cannot know:
 *
 *   - {@see targetSqlId}, the target row's SQL identity, which the compiler
 *     derives from the resolver's own model rather than taking from a caller;
 *   - {@see negate}, the one piece of position-dependent state a recursive step
 *     derives for its children — whether the current subtree is negated, flipped
 *     at each `not` so that negation lands on the leaves.
 *
 * Everything else a leaf needs is read straight off {@see input}: the user, the
 * query factory the leaves are built from, the effective check-time context bag,
 * the loaded target row when there is one, and the cross-schema frames already on
 * this compile path. Holding the input instead of copying its fields is what
 * keeps the two objects from drifting — a leaf descending into another schema
 * reads the same values it then hands to
 * {@see CompilationInput::descending()}.
 *
 * The connector a predicate attaches under is not here — the walk builds a
 * {@see \Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode} whose operands each carry
 * their own connector, so position no longer has to be threaded through.
 *
 * Nor is the host query: {@see CompilationInput::$queries} is a
 * {@see QueryFactory}, which is all the walk ever wanted from it — a source of
 * fresh builders plus the grammar to quote identifiers with. Carrying it there is
 * what lets every step take one argument instead of a context and a builder side
 * by side.
 *
 * Immutable: {@see negated} returns a modified copy, so a step can derive a
 * child context without disturbing its own.
 */
final readonly class CompilationContext
{
    /**
     * @param CompilationInput $input The compile as the caller described it,
     *   refined by the compiler's own recursion (the cross-schema path, and B's
     *   unit and context below a schema boundary).
     * @param string|null $targetSqlId The target row's qualified key, derived by
     *   the compiler from the schema's own model — null in a no-target compile.
     *   Its *nullness* is what the walk reads: a row condition cannot be evaluated
     *   without a row, so {@see RuleSetCompiler::conditionLeaf()} folds one to
     *   `false` rather than emitting a reference to a table that is not in the
     *   query. The string itself is passed on to
     *   {@see \Warrant\DSL\ConditionResolver::applyCondition()} unchanged.
     */
    public function __construct(
        public CompilationInput $input,
        public ?string $targetSqlId,
        public bool $negate = false,
    ) {
    }

    /**
     * Derive a copy with the negation flag toggled (crossing a `not`).
     */
    public function negated(): self
    {
        return new self(
            input: $this->input,
            targetSqlId: $this->targetSqlId,
            negate: ! $this->negate,
        );
    }
}
