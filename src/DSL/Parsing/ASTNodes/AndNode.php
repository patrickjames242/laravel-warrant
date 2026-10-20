<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

readonly class AndNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $leftSide,
        public IBooleanExpressionNode $rightSide,
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
