<?php

namespace Warrant\Schema;

/**
 * A schema condition, resolved from a `#[RowCondition]` or `#[GlobalCondition]`
 * method: its DSL key, the name of the method that implements it, whether it is a
 * row condition (narrowing which rows match) or a global one (a row-independent
 * yes/no or query constraint), and how many DSL arguments it requires.
 *
 * This is a plain value — it carries the method *name*, not a reflection handle —
 * so it is the single object the schema's condition resolution returns and any
 * vocabulary source (including a test double) can construct one directly.
 */
final readonly class ConditionDefinition
{
    /**
     * @param int $requiredArgumentCount The number of DSL arguments the condition
     *   requires — its parameters after the leading context object that have no
     *   default value. A rule supplying fewer is rejected during validation.
     */
    public function __construct(
        public string $key,
        public string $methodName,
        public bool $isRow,
        public int $requiredArgumentCount = 0,
    ) {}

    /**
     * A global condition is simply any condition that is not a row condition.
     */
    public function isGlobal(): bool
    {
        return ! $this->isRow;
    }
}
