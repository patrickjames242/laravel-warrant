<?php

namespace Warrant\Schema\Conditions\Global;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\IProvidesConditions;

/**
 * A schema that declares `#[GlobalCondition]` methods: conditions about the user
 * or the check's context, with no row in scope.
 */
interface IProvidesGlobalConditions extends IProvidesConditions
{
    /**
     * @return list<ConditionDefinition> The `#[GlobalCondition]` methods.
     */
    public function globalConditions(): array;

    /**
     * Calls $condition's method with $c and the DSL arguments $c carries.
     *
     * Answers with the query it constrained, a bool deciding the outcome outright,
     * or null for unknown, which neither grants nor lifts a deny.
     */
    public function invokeGlobalCondition(ConditionDefinition $condition, GlobalConditionContext $c): QueryBuilder|bool|null;
}
