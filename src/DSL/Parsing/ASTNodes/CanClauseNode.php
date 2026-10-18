<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * One `they can <abilities>` clause of a {@see WarrantRuleNode}.
 *
 * Inside an ability block or a rule template's body the clause is generic: it
 * is written `they can` and names no abilities, because the block header or the
 * `@include` names them. Its {@see $abilities} are then empty until
 * {@see WarrantRuleNode::withAbilities()} applies the enclosing ones.
 */
readonly class CanClauseNode implements INode
{
    /* The names this node's parts are placed under in its {@see SyntaxLocation}:
       each is the property the part is stored in. */
    public const PART_ABILITIES = 'abilities';
    public const PART_MESSAGE = 'message';

    /**
     * @param list<string> $abilities Granted ability names (or `*`); empty on a
     *   generic clause.
     * @param mixed $message A denial message written after `because`, which a
     *   `can` clause cannot have: one only ever surfaces for a matching
     *   `cannot`. Held as written for the validator to report; null otherwise.
     */
    public function __construct(
        public array $abilities,
        public mixed $message,
        public SyntaxLocation $location,
    ) {}

    public function isGeneric(): bool
    {
        return $this->abilities === [];
    }
}
