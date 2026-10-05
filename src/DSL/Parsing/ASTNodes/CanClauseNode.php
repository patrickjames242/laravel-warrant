<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * One `they can <abilities>` clause of a {@see WarrantRuleNode}.
 *
 * Inside an ability block or a rule template's body the clause is headless: it
 * is written `they can` and names no abilities, because the block header or the
 * `@include` names them. Its {@see $abilities} are then empty until
 * {@see WarrantRuleNode::withAbilities()} applies the enclosing ones.
 */
readonly class CanClauseNode implements INode
{
    /**
     * @param list<string> $abilities Granted ability names (or `*`); empty on a
     *   headless clause.
     */
    public function __construct(
        public array $abilities,
    ) {}

    public function isHeadless(): bool
    {
        return $this->abilities === [];
    }
}
