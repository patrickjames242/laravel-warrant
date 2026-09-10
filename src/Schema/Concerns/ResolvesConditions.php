<?php

namespace Warrant\Schema\Concerns;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
     * the outcome outright, an expression / {@see WarrantConditionBuilder} to
     * derive itself from other conditions, or null to answer unknown — see
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
     *   predicate about a row that isn't there.
     * @param array<int, mixed> $arguments The resolved DSL arguments for the condition.
     * @param array<string, mixed> $context The effective check-time context bag.
     * @param string|null $rowQualifier The SQL name the target row answers to where
     *   this predicate lands — see
     *   {@see \Warrant\DSL\ConditionResolver::applyCondition()}. Null falls back to
     *   the schema model's own table.
     */
    public function applyConditionFilter(
        string $conditionKey,
        Authenticatable $currentUser,
        Builder $whereClause,
        bool $targeted = false,
        array $arguments = [],
        array $context = [],
        ?Model $targetModel = null,
        ?string $rowQualifier = null
    ): mixed
    {
        $conditionDefinition = static::conditionDefinitionForKey($conditionKey);

        if ($conditionDefinition === null) {
            throw new BadMethodCallException(
                sprintf('Condition [%s] is not defined on schema [%s].', $conditionKey, static::class)
            );
        }

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

        return $this->dispatchDefinition(
            $conditionDefinition,
            $conditionKey,
            $currentUser,
            $whereClause,
            $targeted,
            $arguments,
            $context,
            $targetModel,
            $rowQualifier,
        );
    }

    /**
     * Narrow a where clause to the row a handle's arguments name, by dispatching
     * the schema's {@see \Warrant\Schema\WarrantSchema::matchKey()}.
     *
     * The key is no part of the schema's rule vocabulary — no rule names it, and it
     * carries no condition attribute — but it is dispatched exactly as a row
     * condition is: the same context object, the same Eloquent wrapper over the
     * same where clause, the same positional argument binding. So a key may spend
     * a model scope, follow an alias through {@see RowConditionContext::row()}, and
     * answer unknown by returning null.
     *
     * Always targeted, because a key names a row.
     *
     * @param array<int, mixed> $arguments The resolved handle arguments.
     * @param array<string, mixed> $context The effective check-time context bag.
     * @param string|null $rowQualifier The SQL name the row answers to where this
     *   predicate lands. Null falls back to the schema model's own table.
     */
    public function applyKeyFilter(
        Authenticatable $currentUser,
        Builder $whereClause,
        array $arguments = [],
        array $context = [],
        ?Model $targetModel = null,
        ?string $rowQualifier = null
    ): mixed
    {
        $keyDefinition = static::keyDefinition();

        /* As for a condition, extra arguments are ignored by the call and stay
           reachable on $c->arguments, while a required parameter with no argument
           is a rule-level mistake. Validation reports it against the rule text;
           this guards every other way a handle reaches the compiler. */
        if (count($arguments) < $keyDefinition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'The row key for schema [%s] requires at least %d argument(s), but %d were supplied.',
                static::class,
                $keyDefinition->requiredArgumentCount,
                count($arguments)
            ));
        }

        return $this->dispatchDefinition(
            $keyDefinition,
            $keyDefinition->methodName,
            $currentUser,
            $whereClause,
            true,
            $arguments,
            $context,
            $targetModel,
            $rowQualifier,
        );
    }

    /**
     * The row key a schema gets when it declares no `matchKey()` of its own:
     * equality against the rows' key column.
     *
     * A null names no row — an absent `@context` value, or a model with no key
     * yet — so it answers unknown, which neither grants nor lifts a deny. That is
     * the only safe answer, because the `exists` this predicate lands in cannot
     * report unknown once its subquery is built.
     *
     * Reached by name through {@see ReflectsSchemaDefinition::keyDefinition()},
     * never called directly.
     */
    protected function defaultMatchKey(RowConditionContext $c, mixed $key): ?Builder
    {
        return $key === null ? null : $c->query->where($c->row(), '=', $key);
    }

    /**
     * Build the context a definition's method expects, call it, and normalize what
     * it answered.
     *
     * Shared by the condition and the key dispatches, which differ only in how they
     * find their definition and word an arity failure.
     *
     * @param string $label How to name this definition in an error.
     * @param array<int, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function dispatchDefinition(
        ConditionDefinition $definition,
        string $label,
        Authenticatable $currentUser,
        Builder $whereClause,
        bool $targeted,
        array $arguments,
        array $context,
        ?Model $targetModel,
        ?string $rowQualifier
    ): mixed
    {
        $methodName = $definition->methodName;

        if ($definition->isRow) {
            if (! $targeted) {
                throw new InvalidArgumentException(
                    sprintf('Condition [%s] on schema [%s] requires a target row.', $label, static::class)
                );
            }

            /* The key column comes from the schema's own model, and so does the
               table *unless* the compiler named the row something closer to the
               query it is building — a host query's alias, or a cross-schema hop's.
               Neither ever comes from $targetModel: the SQL identity a condition
               builds must not depend on whether a caller happened to supply an
               instance. */
            $modelClass = static::model;

            /* A virtual table has no model, so there are no scopes to spend. Its
               rows answer to the schema's key unless the compiler named them
               something closer, and their identifying column is whatever the
               schema declared as its `key` — null when it declared none, which
               leaves row() needing a column name and the schema needing a
               matchKey() of its own. */
            if ($modelClass === '') {
                $result = $this->{$methodName}(new RowConditionContext(
                    $currentUser,
                    $whereClause,
                    $rowQualifier ?? static::schemaKey(),
                    static::key === '' ? null : static::key,
                    $arguments,
                    $context,
                    $targetModel,
                ), ...$arguments);

                return $result instanceof EloquentBuilder ? $result->toBase() : $result;
            }

            $model = new $modelClass;
            $rowName = $rowQualifier ?? $model->getTable();

            /* Conditions get an Eloquent builder so they can reuse the scopes
               their model already defines. It wraps the very where clause the
               compiler will read back, so a scope's constraints land where a
               hand-written $c->query->where() would; the compiler still holds
               the base builder and reads it unchanged. Global scopes are left
               off: a condition answers the question it was asked, and nothing
               the model would otherwise volunteer.

               The model keeps its own table, even when the compiler has named
               this row something else. Retargeting it at the alias would make
               getTable() lie: a scope reading it to build a subquery would
               select from a name that is only an alias in the enclosing query.
               So scopes speak the model's table, and one reached through an
               alias fails against a name the query does not have — the same
               way it would anywhere else a scope meets an alias. Conditions
               that must follow the row's name build their own predicate from
               {@see RowConditionContext::row()}. */
            /* Tell the model what its rows are called here, so a scope built on
               warrantQualifyColumn() follows the row rather than naming the
               table. The instance is this call's own, so nothing outlives it. */
            if (method_exists($model, 'setWarrantCurrentRuleAlias')) {
                $model->setWarrantCurrentRuleAlias($rowName);
            }

            $conditionQuery = $model->newModelQuery()->setQuery($whereClause);

            $conditionContext = new RowConditionContext(
                $currentUser,
                $conditionQuery,
                $rowName,
                $model->getKeyName(),
                $arguments,
                $context,
                $targetModel,
            );
        } else {
            $conditionContext = new GlobalConditionContext($currentUser, $whereClause, $arguments, $context);
        }

        $result = $this->{$methodName}($conditionContext, ...$arguments);

        /* A condition that constrained the Eloquent wrapper hands it back, since
           that is what its own calls returned. Callers are promised the query
           builder, and it is the one the wrapper was built around, so unwrap it
           rather than widening what everything downstream has to accept. */
        return $result instanceof EloquentBuilder ? $result->toBase() : $result;
    }

    // -- ConditionResolver ----------------------------------------------------

    public function getConditionDefinition(string $conditionKey): ?ConditionDefinition
    {
        return static::conditionDefinitionForKey($conditionKey);
    }

    public function getKeyDefinition(): ConditionDefinition
    {
        return static::keyDefinition();
    }

    public function applyKey(
        Authenticatable $user,
        \Illuminate\Database\Query\Builder $whereClause,
        array $arguments,
        array $context = [],
        ?Model $targetModel = null,
        ?string $rowQualifier = null,
    ): ?\Illuminate\Database\Query\Builder
    {
        $result = $this->applyKeyFilter($user, $whereClause, $arguments, $context, $targetModel, $rowQualifier);

        /* A key narrows a query or answers unknown, and nothing else. The other
           shapes a condition may answer with have no meaning here: the predicate
           this builds lands inside an `exists` about one row, so a key that
           decided the outcome outright or derived itself into an expression would
           be answering a different question than the one it was asked. */
        if ($result !== null && ! $result instanceof \Illuminate\Database\Query\Builder) {
            throw new InvalidArgumentException(sprintf(
                'The row key for schema [%s] must return the query it constrained, or null to answer '
                    .'unknown; it returned a [%s].',
                static::class,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    public function applyCondition(
        string $conditionKey,
        Authenticatable $user,
        \Illuminate\Database\Query\Builder $whereClause,
        bool $targeted,
        array $parameters,
        array $context = [],
        ?Model $targetModel = null,
        ?string $rowQualifier = null
    ): \Illuminate\Database\Query\Builder|bool|IBooleanExpressionNode|WarrantConditionBuilder|null
    {
        return $this->applyConditionFilter(
            $conditionKey,
            $user,
            $whereClause,
            $targeted,
            $parameters,
            $context,
            $targetModel,
            $rowQualifier,
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
