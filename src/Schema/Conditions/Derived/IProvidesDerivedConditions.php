<?php

namespace Warrant\Schema\Conditions\Derived;

use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\IProvidesConditions;

/**
 * A schema that declares `#[DerivedCondition]` methods: conditions that stand for
 * an expression built from other conditions, expanded before anything compiles.
 */
interface IProvidesDerivedConditions extends IProvidesConditions
{
    /**
     * @return list<ConditionDefinition> The `#[DerivedCondition]` methods.
     */
    public function derivedConditions(): array;

    /**
     * Calls $condition's method with the rule's arguments. Answers with the
     * expression the condition stands for: rule text, syntax, a builder or a node.
     *
     * @param array<int, mixed> $arguments The resolved DSL arguments, in order.
     */
    public function invokeDerivedCondition(ConditionDefinition $condition, array $arguments): mixed;
}
