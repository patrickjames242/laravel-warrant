<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * What follows `for` in `can(...)` and `check(...)`: the rows a reference
 * reaches. Either a schema named by key ({@see SchemaHandleNode}) or a row
 * relation of the schema the rule is for ({@see RelationHandleNode}).
 */
interface IHandleNode extends INode
{
    /**
     * Whether the handle selects rows, which makes the reference an `exists` over
     * them. An unbound schema handle selects none, and asks about the schema
     * with no row at all.
     */
    public function isRowBound(): bool;
}
