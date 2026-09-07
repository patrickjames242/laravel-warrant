<?php

namespace Warrant\DSL\Compiling;

/**
 * The state {@see RuleSetCompiler} threads as it walks a boolean expression tree.
 *
 * It is the {@see CompilationInput} as it stands at this point in the compile,
 * plus {@see negate} — the one piece of position-dependent state a recursive step
 * derives for its children, flipped at each `not` so that negation lands on the
 * leaves. That is the whole of what the walk knows and the input does not, which
 * is why it is the only field here.
 *
 * Everything else a leaf needs is read straight off {@see input}: the user, the
 * query factory the leaves are built from, whether a row is in scope (already
 * narrowed against the schema's own model by {@see RuleSetCompiler::compile()},
 * so there is one such flag and not two), the effective check-time context bag,
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
     *   normalized and then refined by the compiler's own recursion (the target
     *   narrowed to what the schema can support, the cross-schema path, and B's
     *   unit and context below a schema boundary).
     */
    public function __construct(
        public CompilationInput $input,
        public bool $negate = false,
    ) {
    }

    /**
     * Derive a copy with the negation flag toggled (crossing a `not`).
     */
    public function negated(): self
    {
        return new self(input: $this->input, negate: ! $this->negate);
    }
}
