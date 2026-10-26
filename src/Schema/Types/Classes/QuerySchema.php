<?php

namespace Warrant\Schema\Types\Classes;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * A schema whose rows are a query's output: a database view defined in the
 * schema instead of in a migration.
 *
 * Its rows are named by {@see key()}, a `matchKey()`, or both, so every row can be
 * found and targeted checks always work against one.
 *
 * ```php
 * final class ShiftDaySchema extends QuerySchema
 * {
 *     #[Ability] public const ASSIGN = 'assign';
 *
 *     public function rows(): QueryBuilder
 *     {
 *         return DB::table('teams')
 *             ->crossJoin('calendar_days')
 *             ->select(['teams.id as team_id', 'calendar_days.day']);
 *     }
 *
 *     protected function key(): array { return ['team_id', 'day']; }
 * }
 * ```
 */
abstract class QuerySchema extends RowSchema
{
    /**
     * The query whose output is the rows.
     *
     * It takes no user and no context: it says what the rows are, and the rules
     * say who may touch them. Filtering by user here would hide an access decision
     * from the rule text and from reachability analysis.
     *
     * Called afresh wherever the rows are needed and never kept, so nothing
     * downstream can change a shared builder.
     */
    abstract public function rows(): QueryBuilder;

    /**
     * The column, or columns, one row is identified by: the shorthand for a
     * `matchKey()` that compares each column to its argument. Null for a schema
     * that finds its rows with a `matchKey()` of its own.
     *
     * @return string|list<string>|null
     */
    protected function key(): string|array|null
    {
        return null;
    }

    public function rowsQuery(bool $withoutScopes = false): QueryBuilder
    {
        $rows = $this->rows();

        return $rows->newQuery()->fromSub($rows, $this->rowName());
    }

    public function connection(): ConnectionInterface
    {
        return $this->rows()->getConnection();
    }

    /**
     * A fixed alias. The schema's key would read better in generated SQL, but a
     * schema does not know the key it is registered under.
     */
    public function rowName(): string
    {
        return 'warrant_rows';
    }

    /**
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return array_values((array) ($this->key() ?? []));
    }

    /**
     * The where clause itself: there is no model, so there are no scopes to wrap
     * it for.
     */
    public function conditionQuery(QueryBuilder $whereClause, string $rowName): Builder
    {
        return $whereClause;
    }

    protected function verifyDeclarations(): void
    {
        parent::verifyDeclarations();

        if ($this->keyColumns() === [] && ! method_exists($this, 'matchKey')) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] has no way to find one of its rows; declare key() for the column or columns its '
                    .'rows are identified by, or a matchKey() of its own.',
                static::class,
            ));
        }
    }
}
