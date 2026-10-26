<?php

namespace Warrant\Schema\Conditions\Derived;

use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\Conditions\ReflectsConditions;

/**
 * Implements {@see \Warrant\Schema\Conditions\Derived\IProvidesDerivedConditions} over the
 * schema's `#[DerivedCondition]` methods.
 */
trait ReflectsDerivedConditions
{
    use ReflectsConditions;

    /**
     * @return list<ConditionDefinition>
     */
    public function derivedConditions(): array
    {
        return static::conditionMethodsOfKind(ConditionKind::Derived);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function invokeDerivedCondition(ConditionDefinition $condition, array $arguments): mixed
    {
        return $this->{$condition->methodName}(...$arguments);
    }
}
