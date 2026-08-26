<?php

namespace Warrant\RuleSyntaxTree;

/**
 * A cross-schema condition check: `check(<predicate> for <handle> [with <map>])`.
 *
 * Delegates a domain question to another schema ({@see $schemaKey}) by evaluating
 * {@see $predicate} — a boolean expression whose leaves are that schema's own
 * declared conditions — either against a specific row ({@see $isRowBound} true,
 * the `schema(@context id)` form) or globally with no row at all
 * ({@see $isRowBound} false, the bare `schema` form). Unlike {@see CrossSchemaCanNode}
 * it never recurses into the target schema's *rules*: it is pure condition
 * dispatch, so it carries no cycle risk.
 *
 * {@see $isRowBound} is tracked separately from {@see $boundRow} because a row
 * selector may itself resolve to `null` (a `null` literal or a `:name` binding),
 * which must stay distinct from an unbound handle.
 *
 * As with {@see CrossSchemaCanNode}, {@see $boundRow} and the values of
 * {@see $contextMap} hold what the parser resolved: concrete scalars for inline
 * literals and `:name` / `?` bindings, a symbolic {@see ContextRef} for a
 * `@context <key>` reference (filled per check at compile time), or a symbolic
 * {@see ColumnRef} for a `@column <schema>.<column>` reference (resolved to a
 * grammar-wrapped column Expression at compile time). The condition
 * leaves inside {@see $predicate} are validated against — and compiled with — the
 * *target* schema's vocabulary, not the owning schema's.
 */
readonly class CrossSchemaConditionNode implements IBooleanExpressionNode
{
    /**
     * @param array<string, mixed> $contextMap Explicit boundary context, keyed by
     *   the target schema's key name; values are scalars, {@see ContextRef}s, or
     *   {@see ColumnRef}s.
     */
    public function __construct(
        public string $schemaKey,
        public IBooleanExpressionNode $predicate,
        public bool $isRowBound = false,
        public mixed $boundRow = null,
        public array $contextMap = [],
    ) {
    }
}
