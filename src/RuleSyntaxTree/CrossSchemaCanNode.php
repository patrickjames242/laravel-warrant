<?php

namespace Warrant\RuleSyntaxTree;

/**
 * A cross-schema ability check: `can(<ability> for <handle> [with <map>])`.
 *
 * Asks whether the current user holds {@see $ability} on another schema
 * ({@see $schemaKey}) — either on a specific row ({@see $isRowBound} true, the
 * `schema(@context id)` form) or with no row at all ({@see $isRowBound} false,
 * the bare `schema` / capability-schema form). {@see $isRowBound} is tracked
 * separately from {@see $boundRow} because a row selector may itself resolve to
 * `null` (a `null` literal or a `:name` binding), which must stay distinct from
 * an unbound handle.
 *
 * Like {@see ConditionNode::$parameters}, {@see $boundRow} and the values of
 * {@see $contextMap} hold what the parser resolved: concrete scalars for inline
 * literals and `:name` / `?` bindings, a symbolic {@see ContextRef} for a
 * `@context <key>` reference (filled per check at compile time), or a symbolic
 * {@see ColumnRef} for a `@column <schema>.<column>` reference (resolved to a
 * grammar-wrapped column Expression at compile time).
 *
 * NOTE: only parsing is implemented. Validation and compilation of this node are
 * not yet wired up — compiling a rule that uses it currently throws.
 */
readonly class CrossSchemaCanNode implements IBooleanExpressionNode
{
    /**
     * @param array<string, mixed> $contextMap Explicit boundary context, keyed by
     *   the target schema's key name; values are scalars, {@see ContextRef}s, or
     *   {@see ColumnRef}s.
     */
    public function __construct(
        public string $schemaKey,
        public string $ability,
        public bool $isRowBound = false,
        public mixed $boundRow = null,
        public array $contextMap = [],
    ) {
    }
}
