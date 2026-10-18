<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

readonly class NotNode implements IBooleanExpressionNode
{
    public function __construct(
        public IBooleanExpressionNode $operand,
        public SyntaxLocation $location,
    ){

    }
}