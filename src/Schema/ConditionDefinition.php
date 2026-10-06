<?php

namespace Warrant\Schema;

/**
 * A schema condition, resolved from a `#[RowCondition]`, `#[GlobalCondition]` or
 * `#[DerivedCondition]` method: its DSL key, the name of the method that
 * implements it, which {@see ConditionKind} it is, and how many DSL arguments it
 * requires.
 *
 * This is a plain value — it carries the method *name*, not a reflection handle —
 * so it is the single object the schema's condition resolution returns and any
 * vocabulary source (including a test double) can construct one directly.
 */
final readonly class ConditionDefinition
{
    /**
     * @param int $requiredArgumentCount The number of DSL arguments the condition
     *   requires — its parameters with no default value, after the leading context
     *   object for a row or global condition (a derived one has none). Supplying
     *   fewer is rejected wherever the condition is reached, and by validation
     *   ahead of that for a rule written as text.
     */
    public function __construct(
        public string $key,
        public string $methodName,
        public ConditionKind $kind,
        public int $requiredArgumentCount = 0,
    ) {}

    public function isRow(): bool
    {
        return $this->kind === ConditionKind::Row;
    }

    public function isGlobal(): bool
    {
        return $this->kind === ConditionKind::Global;
    }

    public function isDerived(): bool
    {
        return $this->kind === ConditionKind::Derived;
    }
}
