<?php

namespace Warrant\DSL\Compiling\Units;

/**
 * What a single compile is *about* — the thing being turned into a predicate.
 *
 * There are three, and they differ in what they need: a gate and an ability are
 * both resolved against a {@see \Warrant\Rules\WarrantRuleSet} under the
 * deny-overrides formula, while a condition is an expression tree compiled in
 * isolation with no rule set at all. Making them separate types is what lets the
 * rule set be a required constructor argument exactly where it is required,
 * instead of a nullable field that two of the three cases would have to ignore.
 *
 * The unit is carried by a {@see \Warrant\DSL\Compiling\CompilationInput}, which
 * holds everything that is common to all three.
 *
 * @see GateUnit
 * @see AbilityUnit
 * @see ConditionUnit
 */
interface CompilationUnit
{
}
