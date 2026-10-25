<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

readonly class ConditionNode implements BooleanExpressionNode
{

    /**
     * @param list<Node> $parameters The arguments the condition is called with.
     */
    public function __construct(
        public NameNode $conditionKey,
        public array $parameters,
        public SyntaxLocation $location,
    ){

    }

    public function children(): array
    {
        return [
            $this->conditionKey,
            ...$this->parameters,
        ];
    }
}
