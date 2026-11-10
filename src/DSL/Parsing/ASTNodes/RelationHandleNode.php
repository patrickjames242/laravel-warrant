<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A handle that follows a row relation: `its <relation>`. The relation is a
 * `#[RowRelation]` of the schema the rule is for, and the rows it reaches are
 * those related to the row the rule is about, so the handle always selects rows.
 */
final readonly class RelationHandleNode implements IHandleNode
{
    /**
     * @param NameNode $relation The relation's name, as rule text writes it.
     */
    public function __construct(public NameNode $relation, public SyntaxLocation $location)
    {
    }

    public function isRowBound(): bool
    {
        return true;
    }

    public function rowSelector(): array
    {
        return [];
    }

    public function children(): array
    {
        return [$this->relation];
    }
}
