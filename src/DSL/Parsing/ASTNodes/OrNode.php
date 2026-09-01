<?php

namespace Warrant\DSL\Parsing\ASTNodes;

readonly class OrNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $leftSide,
        public IBooleanExpressionNode $rightSide,
    ){

    }
}