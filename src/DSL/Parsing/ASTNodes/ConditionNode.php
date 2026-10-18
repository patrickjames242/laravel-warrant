<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

readonly class ConditionNode implements IBooleanExpressionNode
{
    /* The names this node's parts are placed under in its {@see SyntaxLocation}:
       each is the property the part is stored in. */
    public const PART_CONDITION_KEY = 'conditionKey';
    public const PART_PARAMETERS = 'parameters';


    public function __construct(
        public string $conditionKey,
        public array $parameters,
        public SyntaxLocation $location,
    ){

    }

}