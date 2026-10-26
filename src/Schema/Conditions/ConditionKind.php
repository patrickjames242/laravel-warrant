<?php

namespace Warrant\Schema\Conditions;

use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowConditionContext;

/**
 * What kind of condition a {@see ConditionDefinition} describes, which decides
 * when and how it is answered.
 */
enum ConditionKind
{
    /**
     * `#[RowCondition]`: narrows which rows match. Handed a
     * {@see RowConditionContext}, and needs a row in scope.
     */
    case Row;

    /**
     * `#[GlobalCondition]`: a row-independent yes/no or query constraint. Handed a
     * {@see GlobalConditionContext}.
     */
    case Global;

    /**
     * `#[DerivedCondition]`: answers with an expression built from other
     * conditions rather than with SQL. Handed no context object, only its
     * arguments, so it is expanded into its expression before anything compiles.
     */
    case Derived;
}
