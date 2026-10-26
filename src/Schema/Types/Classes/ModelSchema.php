<?php

namespace Warrant\Schema\Types\Classes;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;
use Warrant\Schema\Types\Interfaces\IModelSchema;

/**
 * A schema whose rows are an Eloquent model's table. The model answers for the
 * table's name, its key and its connection.
 *
 * ```php
 * final class DocumentSchema extends ModelSchema
 * {
 *     public const model = Document::class;
 *
 *     #[Ability] public const VIEW = 'view';
 *
 *     #[RowCondition]
 *     public function isOwner(RowConditionContext $c): Builder
 *     {
 *         return $c->query->where($c->column('owner_id'), $c->user->getAuthIdentifier());
 *     }
 * }
 * ```
 */
abstract class ModelSchema extends RowSchema implements IModelSchema
{
    /**
     * The model whose table holds the rows: the schema's `model` constant.
     *
     * ```php
     * public const model = Document::class;
     * ```
     *
     * A schema that works its model out some other way overrides this instead.
     *
     * @return class-string<Model>
     */
    public static function model(): string
    {
        if (! defined(static::class.'::model')) {
            throw new LogicException(sprintf(
                'Schema [%s] names no model; declare `public const model = YourModel::class;`, or override '
                    .'model().',
                static::class,
            ));
        }

        return static::model;
    }

    public function rowsQuery(bool $withoutScopes = false): QueryBuilder
    {
        $model = $this->newModel();

        return ($withoutScopes ? $model->newQueryWithoutScopes() : $model->newQuery())->getQuery();
    }

    public function connection(): ConnectionInterface
    {
        return $this->newModel()->getConnection();
    }

    public function rowName(): string
    {
        return $this->newModel()->getTable();
    }

    /**
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return [$this->newModel()->getKeyName()];
    }

    /**
     * An Eloquent builder over $whereClause, so a condition can use the scopes the
     * model already defines. Global scopes are left off: a condition answers the
     * question it was asked, and nothing the model would otherwise volunteer.
     *
     * The model keeps its own table, so a scope that reads `getTable()` still
     * names the table. It is told its rows are called $rowName here, so a scope
     * built on `warrantQualifyColumn()` follows an alias instead.
     */
    public function conditionQuery(QueryBuilder $whereClause, string $rowName): Builder
    {
        $model = $this->newModel();

        if (method_exists($model, 'setWarrantCurrentRuleAlias')) {
            $model->setWarrantCurrentRuleAlias($rowName);
        }

        return $model->newModelQuery()->setQuery($whereClause);
    }

    private function newModel(): Model
    {
        return new (static::model());
    }
}
