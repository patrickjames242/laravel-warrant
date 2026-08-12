<?php

namespace Warrant\RuleSyntaxTree;

readonly class ConditionNode implements IBooleanExpressionNode
{

    public function __construct(
        public string $conditionKey,
        public array $parameters = [],
    ){

    }

}