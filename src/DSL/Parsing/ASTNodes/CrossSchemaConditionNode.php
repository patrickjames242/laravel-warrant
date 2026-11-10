<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A cross-schema condition check:
 * `check(<predicate> for <handle> [as <alias>] [with <map>])`.
 *
 * Delegates a domain question to another schema by evaluating {@see $predicate} —
 * a boolean expression read against that schema's vocabulary — over the rows
 * {@see $handle} reaches: one row, the rows a row relation reaches, or no row at
 * all for an unbound schema handle.
 *
 * The predicate is a full expression, not just a list of conditions: it may nest
 * another `check(...)`, whose handle is read in *this* reference's frame, and it
 * may hold a `can(...)`, which asks about an ability of the schema this reference
 * named. What it may not hold is a constant, which would decide the predicate
 * while asking the target nothing.
 *
 * As with {@see CrossSchemaCanNode}, the values of {@see $contextMap} hold what
 * was written. The condition leaves inside {@see $predicate} are validated
 * against — and compiled with — the *target* schema's vocabulary, not the owning
 * schema's.
 *
 * Validated by {@see \Warrant\DSL\Validation\ValidatesExpressions::assertCrossSchemaConditionValid()}
 * and compiled by {@see \Warrant\DSL\Compiling\RuleSetCompiler::crossSchemaCheckLeaf()}.
 */
readonly class CrossSchemaConditionNode implements IBooleanExpressionNode
{
    /**
     * @param IHandleNode $handle The rows the predicate is asked about.
     * @param list<WithEntryNode> $contextMap Explicit boundary context, each
     *   entry under one of the target schema's key names, in the order written.
     * @param NameNode|null $alias The SQL name this reference's own subquery gives
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
        public IHandleNode $handle,
        public IBooleanExpressionNode $predicate,
        public array $contextMap,
        public ?NameNode $alias,
        public SyntaxLocation $location,
    ) {
    }

    public function children(): array
    {
        return [
            $this->predicate,
            $this->handle,
            ...$this->contextMap,
            ...($this->alias === null ? [] : [$this->alias]),
        ];
    }
}
