<?php

namespace Warrant\Schema\Concerns;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\Schema\ConditionDefinition;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;

/**
 * The vocabulary seam the compiler dispatches into (the {@see \Warrant\DSL\ConditionResolver}
 * implementation): validating ability names and applying a named condition's SQL
 * predicate to a builder.
 */
trait ResolvesConditions
{
    /**
     * Applies a named condition filter to the provided builder.
     *
     * A condition method may return the builder it constrained, a bool to decide
     * the outcome outright, or an expression / {@see WarrantConditionBuilder} to
     * derive itself from other conditions — see
     * {@see \Warrant\DSL\ConditionResolver::applyCondition()}.
     *
     * The named condition must correspond to a public method declared on the
     * schema and marked with either `#[RowCondition(...)]` or
     * `#[GlobalCondition(...)]`. The method's first parameter is always the
     * context object carrying the user, the builder, the DSL arguments, and —
     * for row conditions — the target row's SQL identity. Any further parameters
     * receive the DSL arguments positionally (parameter #2 -> argument[0], and so
     * on); the full argument list also remains available via `$c->arguments`. The
     * builder is mutated in place and also returned for convenience.
     *
     * @param bool $targeted Whether a target row is in scope. A row condition
     *   cannot run without one, so it is rejected here rather than emitting a
     *   predicate about a row that isn't there. The row's table and key column are
     *   derived from the schema's own model below, never passed in.
     * @param array<int, mixed> $arguments The resolved DSL arguments for the condition.
     * @param array<string, mixed> $context The effective check-time context bag.
     */
    public function applyConditionFilter(
        string $conditionKey,
        Authenticatable $currentUser,
        Builder $whereClause,
        bool $targeted = false,
        array $arguments = [],
        array $context = [],
        ?Model $targetModel = null
    ): mixed
    {
        $conditionDefinition = static::conditionDefinitionForKey($conditionKey);

        if ($conditionDefinition === null) {
            throw new BadMethodCallException(
                sprintf('Condition [%s] is not defined on schema [%s].', $conditionKey, static::class)
            );
        }

        $methodName = $conditionDefinition->methodName;

        /* The context object is always the method's first parameter; any further
           parameters are the condition's DSL arguments, bound positionally
           (parameter #2 -> argument[0], and so on). Supplying more arguments than
           declared parameters is fine — the extras are ignored by the call and stay
           reachable via $c->arguments — but a required parameter with no matching
           argument is a rule-level mistake, so reject it here with a branded error
           (validation catches this earlier; this guards direct callers too). */
        if (count($arguments) < $conditionDefinition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.',
                $conditionKey,
                static::class,
                $conditionDefinition->requiredArgumentCount,
                count($arguments)
            ));
        }

        if ($conditionDefinition->isRow) {
            if (! $targeted) {
                throw new InvalidArgumentException(
                    sprintf('Condition [%s] on schema [%s] requires a target row.', $conditionKey, static::class)
                );
            }

            /* Table and key come from the schema's own model, never from
               $targetModel and never from the caller: they are the same values,
               and the SQL identity a condition builds must not depend on whether a
               caller happened to supply an instance. */
            $modelClass = static::model;
            $model = new $modelClass;

            $conditionContext = new RowConditionContext(
                $currentUser,
                $whereClause,
                $model->getTable(),
                $model->getKeyName(),
                $arguments,
                $context,
                $targetModel,
            );
        } else {
            $conditionContext = new GlobalConditionContext($currentUser, $whereClause, $arguments, $context);
        }

        return $this->{$methodName}($conditionContext, ...$arguments);
    }

    // -- ConditionResolver ----------------------------------------------------

    public function getConditionDefinition(string $conditionKey): ?ConditionDefinition
    {
        return static::conditionDefinitionForKey($conditionKey);
    }

    public function applyCondition(
        string $conditionKey,
        Authenticatable $user,
        \Illuminate\Database\Query\Builder $whereClause,
        bool $targeted,
        array $parameters,
        array $context = [],
        ?Model $targetModel = null
    ): \Illuminate\Database\Query\Builder|bool|IBooleanExpressionNode|WarrantConditionBuilder
    {
        return $this->applyConditionFilter(
            $conditionKey,
            $user,
            $whereClause,
            $targeted,
            $parameters,
            $context,
            $targetModel,
        );
    }

    /**
     * Validate and normalize a requested ability list against the schema's
     * declared abilities.
     *
     * @return array<int, string>
     */
    public function normalizeAbilities(string|array $abilities): array
    {
        $abilities = collect(is_array($abilities) ? $abilities : [$abilities])
            ->filter(fn(mixed $ability) => is_string($ability) && $ability !== '')
            ->values()
            ->all();

        foreach ($abilities as $ability) {
            if (!isset($this->abilityLookup[$ability])) {
                throw new InvalidArgumentException(
                    sprintf('Ability [%s] is not defined on schema [%s].', $ability, static::class)
                );
            }
        }

        return $abilities;
    }
}
