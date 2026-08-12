<?php

namespace Warrant\RuleSyntaxTree;

readonly class AndNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $leftSide,
        public IBooleanExpressionNode $rightSide,
    ){

    }

}