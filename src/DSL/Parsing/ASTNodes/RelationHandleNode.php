<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A handle that follows a row relation: `its <relation>`. The relation is a
 * `#[RowRelation]` of the schema the rule is for, and the rows it reaches are
 * those related to the row the rule is about, so the handle always selects rows.
 * A relation may reach many, so the handle may be written after a quantifier, as
 * `all of its <relation>`.
 */
final readonly class RelationHandleNode implements IHandleNode
{
    /**
     * @param NameNode $relation The relation's name, as rule text writes it.
     * @param Quantifier|null $writtenQuantifier The `any of` or `all of` written
     *   before `its`, or null when none was.
     */
    public function __construct(
        public NameNode $relation,
        public ?Quantifier $writtenQuantifier,
        public SyntaxLocation $location,
    ) {
    }

    public function quantifier(): ?Quantifier
    {
        return $this->writtenQuantifier;
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
