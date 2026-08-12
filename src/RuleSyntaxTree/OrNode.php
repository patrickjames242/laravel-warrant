<?php

namespace Warrant\RuleSyntaxTree;

readonly class OrNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $leftSide,
        public IBooleanExpressionNode $rightSide,
    ){

    }
}