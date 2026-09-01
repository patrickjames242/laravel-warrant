<?php

namespace Warrant\DSL\Compiling\WhereClause;

use Illuminate\Database\Query\Builder;

/**
 * One operand of a {@see CompiledWhereClauseNode} — a value, the connector it
 * attaches under, and whether it is negated.
 *
 * The value is a literal `bool`, a {@see Builder} that a condition augmented
 * (where clauses only), or another node. $negated inverts just this operand: it
 * is how a `not` that has been pushed down to a leaf is carried, so a negated
 * leaf emits as `not (...)` rather than needing an `"and not"` connector string
 * threaded through the walk.
 *
 * $boolean is only authoritative once the operand sits in a group built by
 * {@see CompiledWhereClauseNode::simplify}, where every operand attaches under
 * the group's one connector. While a node is still being assembled the
 * connectors are mixed and their precedence is unresolved, and an operand handed
 * back mid-simplification may still carry the connector it was added with.
 */
final readonly class CompiledWhereClauseOperand
{
    public function __construct(
        public string $boolean,
        public bool|Builder|CompiledWhereClauseNode $value,
        public bool $negated = false,
    ) {
    }

    /**
     * A copy attaching under a different connector.
     */
    public function under(string $boolean): self
    {
        return new self($boolean, $this->value, $this->negated);
    }

    /**
     * A copy with the negation flipped.
     */
    public function flipNegation(): self
    {
        return new self($this->boolean, $this->value, ! $this->negated);
    }
}
