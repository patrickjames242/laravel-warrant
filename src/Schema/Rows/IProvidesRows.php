<?php

namespace Warrant\Schema\Rows;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\Row\RowConditionContext;

/**
 * A schema with rows: where they come from, what they are called in SQL, and how
 * one of them is found.
 */
interface IProvidesRows
{
    /**
     * A fresh query over the rows, selected so their columns answer to
     * {@see rowName()}.
     *
     * @param bool $withoutScopes Leave off the global scopes a model would apply.
     */
    public function rowsQuery(bool $withoutScopes = false): QueryBuilder;

    /**
     * The connection the rows live on.
     */
    public function connection(): ConnectionInterface;

    /**
     * The name the rows go by in SQL when nothing closer to the query names them.
     */
    public function rowName(): string;

    /**
     * The column or columns one row is identified by. Empty for rows found only
     * by a `matchKey()` of the schema's own.
     *
     * @return list<string>
     */
    public function keyColumns(): array;

    /**
     * The builder a row condition constrains: $whereClause itself, or a wrapper
     * around it that the schema's model scopes can be used on. Constraints added
     * to either land in $whereClause.
     *
     * @param string $rowName The name the row goes by where the condition's
     *   predicate lands.
     */
    public function conditionQuery(QueryBuilder $whereClause, string $rowName): Builder;

    /**
     * How one row is found from a handle's arguments: the schema's `matchKey()`,
     * or the default that compares {@see keyColumns()} to them in order.
     */
    public function rowMatcher(): ConditionDefinition;

    /**
     * Calls the row matcher's method with $c and the handle arguments $c carries.
     * Answers with the query narrowed to one row, or null for unknown.
     */
    public function invokeRowMatcher(RowConditionContext $c): ?QueryBuilder;
}
