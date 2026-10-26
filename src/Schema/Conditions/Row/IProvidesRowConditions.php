<?php

namespace Warrant\Schema\Conditions\Row;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\IProvidesConditions;

/**
 * A schema that declares `#[RowCondition]` methods: conditions about one row,
 * which always have a row in scope.
 */
interface IProvidesRowConditions extends IProvidesConditions
{
    /**
     * @return list<ConditionDefinition> The `#[RowCondition]` methods.
     */
    public function rowConditions(): array;

    /**
     * Calls $condition's method with $c and the DSL arguments $c carries.
     *
     * Answers with the query it constrained, a bool deciding the outcome outright,
     * or null for unknown, which neither grants nor lifts a deny.
     */
    public function invokeRowCondition(ConditionDefinition $condition, RowConditionContext $c): QueryBuilder|bool|null;
}
