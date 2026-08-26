<?php

namespace Warrant\Schema;

use ReflectionMethod;

/**
 * A schema condition, resolved from a `#[RowCondition]` or `#[GlobalCondition]`
 * method: its DSL key, the method that implements it, and whether it is a row
 * condition (narrowing which rows match) or a global one (a row-independent
 * yes/no or query constraint). This is the single object the schema's condition
 * resolution returns.
 */
final readonly class ConditionDefinition
{
    public function __construct(
        public string $key,
        public ReflectionMethod $method,
        public bool $isRow,
    ) {}

    /**
     * A global condition is simply any condition that is not a row condition.
     */
    public function isGlobal(): bool
    {
        return ! $this->isRow;
    }

    /**
     * The number of DSL arguments the condition requires. The method's first
     * parameter is always the context object; every parameter after it binds an
     * argument positionally, so a rule must supply at least this many.
     */
    public function requiredArgumentCount(): int
    {
        return max(0, $this->method->getNumberOfRequiredParameters() - 1);
    }
}
