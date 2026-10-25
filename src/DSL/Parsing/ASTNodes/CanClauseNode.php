<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * One `they can <abilities>` clause of a {@see RuleNode}.
 *
 * Inside an ability block or a rule template's body the clause is generic: it
 * is written `they can` and names no abilities, because the block header or the
 * `@include` names them. Its {@see $abilities} are then empty until
 * {@see RuleNode::withAbilities()} applies the enclosing ones.
 */
readonly class CanClauseNode implements INode
{
    /**
     * @param list<NameNode|BindingNode> $abilities Granted ability names (or
     *   `*`), and bindings whose values name them until expansion puts those
     *   names in their place; empty on a generic clause.
     * @param INode|null $message A denial message written after `because`, which a
     *   `can` clause cannot have: one only ever surfaces for a matching
     *   `cannot`. Held as written for the validator to report; null otherwise.
     */
    public function __construct(
        public array $abilities,
        public ?INode $message,
        public SyntaxLocation $location,
    ) {}

    public function isGeneric(): bool
    {
        return $this->abilities === [];
    }

    public function children(): array
    {
        return [
            ...$this->abilities,
            ...($this->message === null ? [] : [$this->message]),
        ];
    }
}
