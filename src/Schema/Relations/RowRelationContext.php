<?php

namespace Warrant\Schema\Relations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Warrant\Support\StringableExpression;

/**
 * What a `#[RowRelation]` method is handed: the related schema's rows, to narrow
 * to the ones related to this schema's row, and a way to name a column on either
 * side.
 *
 * Each side goes by whatever name the query gives it. This schema's row may be
 * the host query's table, its alias, or an enclosing hop's rows; the related rows
 * are a hop of their own, renamed when their table is already in scope. So a
 * relation names every column through {@see ownColumn()} and {@see relatedColumn()},
 * never by table:
 *
 *     return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
 *
 * Both columns are expressions, so they compare as columns wherever a builder is
 * handed them, including a where's value position.
 *
 * `relatedQuery` carries none of the related model's global scopes: a relation
 * reaches the rows its method names, and nothing the model would otherwise add.
 */
final readonly class RowRelationContext
{
    /**
     * @param Builder $relatedQuery The related schema's rows under the hop's name.
     * @param string $ownTable The name this schema's row goes by where the hop is
     *   written.
     * @param string $relatedTable The name the related rows go by inside the hop.
     * @param array<string, mixed> $context The related schema's check context.
     */
    public function __construct(
        public Authenticatable $user,
        public Builder $relatedQuery,
        private string $ownTable,
        private string $relatedTable,
        public array $context = [],
    ) {
    }

    /**
     * A column of this schema's row, qualified and quoted:
     * `ownColumn('folder_id')` → `"documents"."folder_id"`.
     */
    public function ownColumn(string $column): StringableExpression
    {
        return new StringableExpression($this->grammar()->wrap($this->ownTable.'.'.$column));
    }

    /**
     * A column of the related rows, qualified and quoted:
     * `relatedColumn('id')` → `"folders"."id"`.
     */
    public function relatedColumn(string $column): StringableExpression
    {
        return new StringableExpression($this->grammar()->wrap($this->relatedTable.'.'.$column));
    }

    private function grammar(): Grammar
    {
        $query = $this->relatedQuery instanceof EloquentBuilder ? $this->relatedQuery->getQuery() : $this->relatedQuery;

        return $query->getGrammar();
    }
}
