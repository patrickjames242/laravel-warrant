<?php

namespace Warrant\RuleSyntaxTree;

readonly class NotNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $operand,
    ){

    }
}