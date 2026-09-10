<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * A cross-schema condition check:
 * `check(<predicate> for <handle> [as <alias>] [with <map>])`.
 *
 * Delegates a domain question to another schema ({@see $schemaKey}) by evaluating
 * {@see $predicate} — a boolean expression read against that schema's vocabulary —
 * either against a specific row ({@see $isRowBound} true, the `schema(@context id)`
 * form) or globally with no row at all ({@see $isRowBound} false, the bare `schema`
 * form).
 *
 * The predicate is a full expression, not just a list of conditions: it may nest
 * another `check(...)`, whose handle is read in *this* reference's frame, and it
 * may hold a `can(...)`, which asks about an ability of the schema this reference
 * named. What it may not hold is a constant, which would decide the predicate
 * while asking the target nothing.
 *
 * {@see $isRowBound} is tracked separately from {@see $boundKey} because an empty
 * argument list is not an absent handle: `schema()` addresses a row with a key
 * that requires no arguments, while bare `schema` addresses no row at all.
 *
 * As with {@see CrossSchemaCanNode}, the elements of {@see $boundKey} and the
 * values of {@see $contextMap} hold what the parser resolved: concrete scalars for inline
 * literals and `:name` / `?` bindings, a symbolic {@see ContextRef} for a
 * `@context <key>` reference (filled per check at compile time), or a symbolic
 * {@see ColumnRef} for a `@column [<name>.]<column>` reference (resolved to a
 * grammar-wrapped column Expression at compile time). The condition
 * leaves inside {@see $predicate} are validated against — and compiled with — the
 * *target* schema's vocabulary, not the owning schema's.
 *
 * Validated by {@see \Warrant\DSL\Parsing\Validation\RuleSetValidator::assertCrossSchemaConditionValid()}
 * and compiled by {@see \Warrant\DSL\Compiling\RuleSetCompiler::crossSchemaCheckLeaf()}.
 */
readonly class CrossSchemaConditionNode implements IBooleanExpressionNode
{
    /**
     * @param array<int, mixed> $boundKey The handle's row-selector arguments, bound
     *   positionally to the target schema's row key. Empty on an unbound handle,
     *   and also on `schema()`, whose key requires no arguments.
     * @param array<string, mixed> $contextMap Explicit boundary context, keyed by
     *   the target schema's key name; values are scalars, {@see ContextRef}s, or
     *   {@see ColumnRef}s.
     * @param string|null $alias The SQL name this reference's own subquery gives
     *   the target's rows — the `as <alias>` tail. Null leaves the subquery's
     *   `from` unaliased, which is the ordinary case; naming it matters when the
     *   same table is already in scope further out (see
     *   {@see \Warrant\DSL\Compiling\AliasScope}). Only meaningful on a
     *   row-bound handle: an unbound one selects nothing to name.
     *   On a `check(...)` the alias is also a *name the predicate can use*: it
     *   leaves the target's schema key still meaning the enclosing frame, so a
     *   predicate over two frames of one table can tell them apart.
     */
    public function __construct(
        public string $schemaKey,
        public IBooleanExpressionNode $predicate,
        public bool $isRowBound = false,
        public array $boundKey = [],
        public array $contextMap = [],
        public ?string $alias = null,
    ) {
    }
}
