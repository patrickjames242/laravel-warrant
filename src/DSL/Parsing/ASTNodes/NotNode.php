<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

readonly class NotNode implements BooleanExpressionNode
{
    public function __construct(
        public BooleanExpressionNode $operand,
        public SyntaxLocation $location,
    ){

    }

    public function children(): array
    {
        return [$this->operand];
    }
}
