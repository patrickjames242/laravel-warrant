<?php

namespace Warrant\Schema\Conditions\Global;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\Conditions\ReflectsConditions;

/**
 * Implements {@see \Warrant\Schema\Conditions\Global\IProvidesGlobalConditions} over the
 * schema's `#[GlobalCondition]` methods.
 */
trait ReflectsGlobalConditions
{
    use ReflectsConditions;

    /**
     * @return list<ConditionDefinition>
     */
    public function globalConditions(): array
    {
        return static::conditionMethodsOfKind(ConditionKind::Global);
    }

    public function invokeGlobalCondition(ConditionDefinition $condition, GlobalConditionContext $c): QueryBuilder|bool|null
    {
        return $this->invokeConditionMethod($condition, $c);
    }
}
