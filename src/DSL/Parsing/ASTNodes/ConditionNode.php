<?php

namespace Warrant\DSL\Parsing\ASTNodes;

readonly class ConditionNode implements IBooleanExpressionNode
{
    /* The names a {@see \Warrant\DSL\Parsing\Positions\SourceMap} records this
       node's parts under: each is the property the part is stored in. */
    public const PART_CONDITION_KEY = 'conditionKey';
    public const PART_PARAMETERS = 'parameters';


    public function __construct(
        public string $conditionKey,
        public array $parameters = [],
    ){

    }

}