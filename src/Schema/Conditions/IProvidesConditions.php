<?php

namespace Warrant\Schema\Conditions;

/**
 * A schema that declares conditions of at least one kind.
 *
 * The kinds share one set of names: a key names at most one condition across
 * every kind the schema provides. So a rule names a condition without saying
 * which kind it is, and {@see condition()} answers with whichever kind it is.
 */
interface IProvidesConditions
{
    /**
     * Every condition the schema declares, of every kind it provides.
     *
     * @return list<ConditionDefinition>
     */
    public function conditions(): array;

    /**
     * The condition keyed $key, of whatever kind, or null when the schema declares
     * none.
     */
    public function condition(string $key): ?ConditionDefinition;
}
