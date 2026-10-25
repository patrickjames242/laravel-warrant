<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

readonly class AndNode implements BooleanExpressionNode
{
    public function __construct(
        public BooleanExpressionNode $leftSide,
        public BooleanExpressionNode $rightSide,
        public SyntaxLocation $location,
    ){

    }

    public function children(): array
    {
        return [
            $this->leftSide,
            $this->rightSide,
        ];
    }
}
