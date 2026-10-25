<?php

namespace Warrant\Builders;

use Closure;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * The way in to building rules in PHP instead of rule text. Everything that
 * accepts rule text accepts what these make.
 *
 * ```php
 * Build::ruleSet('timesheets', function ($rule) {
 *     $rule()->if('is_self')->theyCan('view', 'edit');
 *     $rule->include('requires_approval', ['manager'])->for('submit');
 * });
 *
 * Build::rule()
 *     ->if('is_author')
 *     ->orIfCan(Build::can('approve')->for(PayPeriod::class, Ref::context('period_id')))
 *     ->andIfCheck(Build::check('is_open')->for('pay_periods', Ref::column('pay_period_id')))
 *     ->theyCan('submit');
 * ```
 */
final class Build
{
    /** One rule: conditions plus the abilities they grant or deny. */
    public static function rule(): WarrantRuleBuilder
    {
        return new WarrantRuleBuilder;
    }

    /**
     * A condition expression: the `if` half of a rule, with no can/cannot clauses. It is
     * what a schema's `#[DerivedCondition]` returns to build itself from other
     * conditions, and what a rule takes to reuse a condition built elsewhere.
     */
    public static function condition(): WarrantConditionBuilder
    {
        return new WarrantConditionBuilder;
    }

    /**
     * A rule set. The callback receives a {@see RuleSetBuilder}: `$rule()` starts
     * a rule and `$rule->include(...)` an `@include`, and each is finished when
     * the callback returns, with no `toRule()`. Every node of the set is placed
     * in one source.
     *
     * Given a schema key, the answer is a `for <schema>` {@see RuleSetNode}.
     * Given only the callback, it is the entries themselves, headless, as a rule
     * template's body is written.
     *
     * @param string|Closure(RuleSetBuilder): mixed $schemaOrCallback
     * @param (Closure(RuleSetBuilder): mixed)|null $callback
     * @return ($callback is null ? list<IRuleEntryNode> : RuleSetNode)
     */
    public static function ruleSet(string|Closure $schemaOrCallback, ?Closure $callback = null): RuleSetNode|array
    {
        $source = new SyntaxSource(null);
        $rule = new RuleSetBuilder;

        if ($schemaOrCallback instanceof Closure) {
            $schemaOrCallback($rule);

            return $rule->entries($source);
        }

        $callback($rule);

        return new RuleSetNode(
            new NameNode($schemaOrCallback, SyntaxLocation::builtIn($source)),
            $rule->entries($source),
            SyntaxLocation::builtIn($source),
        );
    }

    /**
     * A cross-schema ability reference, `can($ability ...)`, for `ifCan()`,
     * `if()` and a derived condition's answer.
     */
    public static function can(string $ability): CrossSchemaCanBuilder
    {
        return new CrossSchemaCanBuilder($ability);
    }

    /**
     * A cross-schema condition check, `check(...)`, for `ifCheck()`, `if()` and a
     * derived condition's answer. $condition starts its predicate; the rest of
     * the predicate is built on the check like any condition.
     *
     * @param array<int, mixed> $parameters
     */
    public static function check(?string $condition = null, array $parameters = []): CrossSchemaCheckBuilder
    {
        $check = new CrossSchemaCheckBuilder;

        if ($condition !== null) {
            $check->if($condition, $parameters);
        }

        return $check;
    }

    private function __construct()
    {
    }
}
