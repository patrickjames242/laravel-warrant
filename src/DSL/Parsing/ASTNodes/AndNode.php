<?php

namespace Warrant\DSL\Parsing\ASTNodes;

readonly class AndNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $leftSide,
        public IBooleanExpressionNode $rightSide,
    ){

    }

}