<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * An ability check: `can(<ability>)`, or
 * `can(<ability> for <handle> [as <alias>] [with <map>])`.
 *
 * The `for` clause is a boundary. With one, this asks whether the current user
 * holds {@see $ability} on another schema ({@see $schemaKey}) — either on a
 * specific row ({@see $isRowBound} true, the `schema(@context id)` form) or with
 * no row at all ({@see $isRowBound} false, the bare `schema` / capability-schema
 * form). {@see $isRowBound} is tracked separately from {@see $boundKey} because an
 * empty argument list is not an absent handle: `schema()` addresses a row with a
 * key that requires no arguments, while bare `schema` addresses no row at all.
 *
 * Without a `for` clause ({@see $schemaKey} null) nothing is crossed: it asks
 * about another ability of the schema the rule is written on, over the row the
 * rule is already about and under the same check-time context. Only the ability
 * changes, so there is no handle, no context map, and no subquery — the target
 * ability's predicate is compiled into the frame the reference sits in.
 *
 * Like {@see ConditionNode::$parameters}, the elements of {@see $boundKey} and the
 * values of {@see $contextMap} hold what the parser resolved: concrete scalars for inline
 * literals and `:name` / `?` bindings, a symbolic {@see ContextRef} for a
 * `@context <key>` reference (filled per check at compile time), or a symbolic
 * {@see ColumnRef} for a `@column [<name>.]<column>` reference (resolved to a
 * grammar-wrapped column Expression at compile time).
 *
 * Validated by {@see \Warrant\DSL\Validation\ValidatesExpressions::assertCrossSchemaCanValid()}
 * and compiled by {@see \Warrant\DSL\Compiling\RuleSetCompiler::crossSchemaCanLeaf()}.
 */
readonly class CrossSchemaCanNode implements IBooleanExpressionNode
{
    /**
     * @param list<INode> $boundKey The handle's row-selector arguments, bound
     *   positionally to the target schema's row key. Empty on an unbound handle,
     *   and also on `schema()`, whose key requires no arguments.
     * @param list<WithEntryNode> $contextMap Explicit boundary context, each
     *   entry under one of the target schema's key names, in the order written.
     * @param NameNode|null $alias The SQL name this reference's own subquery gives
     *   the target's rows — the `as <alias>` tail. Null leaves the subquery's
     *   `from` unaliased, which is the ordinary case; naming it matters when the
     *   same table is already in scope further out (see
     *   {@see \Warrant\DSL\Compiling\AliasScope}). Only meaningful on a
     *   row-bound handle: an unbound one selects nothing to name.
     */
    /**
     * @param NameNode|null $schemaKey The schema this reference crosses to, or null
     *   for the boundary-free form, which stays on the schema being compiled.
     * @param NameNode|BindingNode $ability The ability asked about, or a binding
     *   whose value names it until expansion puts that name in its place.
     */
    public function __construct(
        public ?NameNode $schemaKey,
        public NameNode|BindingNode $ability,
        public bool $isRowBound,
        public array $boundKey,
        public array $contextMap,
        public ?NameNode $alias,
        public SyntaxLocation $location,
    ) {
    }

    public function children(): array
    {
        return [
            ...($this->schemaKey === null ? [] : [$this->schemaKey]),
            $this->ability,
            ...$this->boundKey,
            ...$this->contextMap,
            ...($this->alias === null ? [] : [$this->alias]),
        ];
    }
}
