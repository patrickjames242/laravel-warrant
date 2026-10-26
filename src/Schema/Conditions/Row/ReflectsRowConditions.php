<?php

namespace Warrant\Schema\Conditions\Row;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\Conditions\ReflectsConditions;

/**
 * Implements {@see \Warrant\Schema\Conditions\Row\IProvidesRowConditions} over the
 * schema's `#[RowCondition]` methods.
 */
trait ReflectsRowConditions
{
    use ReflectsConditions;

    /**
     * @return list<ConditionDefinition>
     */
    public function rowConditions(): array
    {
        return static::conditionMethodsOfKind(ConditionKind::Row);
    }

    public function invokeRowCondition(ConditionDefinition $condition, RowConditionContext $c): QueryBuilder|bool|null
    {
        return $this->invokeConditionMethod($condition, $c);
    }
}
