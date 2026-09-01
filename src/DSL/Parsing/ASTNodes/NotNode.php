<?php

namespace Warrant\DSL\Parsing\ASTNodes;

readonly class NotNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $operand,
    ){

    }
}