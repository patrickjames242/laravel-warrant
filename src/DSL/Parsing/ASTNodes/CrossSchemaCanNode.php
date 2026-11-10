<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * An ability check: `can(<ability>)`, or
 * `can(<ability> for <handle> [as <alias>] [with <map>])`.
 *
 * The `for` clause is a boundary. With one, this asks whether the current user
 * holds {@see $ability} on the rows {@see $handle} reaches: one row of another
 * schema, the rows a row relation reaches, or another schema with no row at all.
 *
 * Without a `for` clause ({@see $handle} null) nothing is crossed: it asks
 * about another ability of the schema the rule is written on, over the row the
 * rule is already about and under the same check-time context. Only the ability
 * changes, so there is no handle, no context map, and no subquery — the target
 * ability's predicate is compiled into the frame the reference sits in.
 *
 * The values of {@see $contextMap} hold what was written: a {@see LiteralNode},
 * a {@see BindingNode}, a symbolic {@see ContextRef} for a `@context <key>`
 * reference (filled per check at compile time), or a symbolic {@see ColumnRef}
 * for a `@column [<name>.]<column>` reference (resolved to a grammar-wrapped
 * column Expression at compile time).
 *
 * Validated by {@see \Warrant\DSL\Validation\ValidatesExpressions::assertCrossSchemaCanValid()}
 * and compiled by {@see \Warrant\DSL\Compiling\RuleSetCompiler::crossSchemaCanLeaf()}.
 */
readonly class CrossSchemaCanNode implements IBooleanExpressionNode
{
    /**
     * @param IHandleNode|null $handle The rows this reference crosses to, or null
     *   for the boundary-free form, which stays on the schema being compiled.
     * @param NameNode|BindingNode $ability The ability asked about, or a binding
     *   whose value names it until expansion puts that name in its place.
     * @param list<WithEntryNode> $contextMap Explicit boundary context, each
     *   entry under one of the target schema's key names, in the order written.
     * @param NameNode|null $alias The SQL name this reference's own subquery gives
     *   the target's rows — the `as <alias>` tail. Null leaves the subquery's
     *   `from` unaliased, which is the ordinary case; naming it matters when the
     *   same table is already in scope further out (see
     *   {@see \Warrant\DSL\Compiling\AliasScope}). Only meaningful on a
     *   row-bound handle: an unbound one selects nothing to name.
     */
    public function __construct(
        public ?IHandleNode $handle,
        public NameNode|BindingNode $ability,
        public array $contextMap,
        public ?NameNode $alias,
        public SyntaxLocation $location,
    ) {
    }

    public function children(): array
    {
        return [
            $this->ability,
            ...($this->handle === null ? [] : [$this->handle]),
            ...$this->contextMap,
            ...($this->alias === null ? [] : [$this->alias]),
        ];
    }
}
