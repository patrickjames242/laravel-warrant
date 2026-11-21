<?php

namespace Warrant\Schema\Conditions;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\Support\StringableExpression;

/**
 * The row of the users table that holds the user a check is for, as a condition
 * reaches it through `userColumn()`.
 *
 * A column of that row is a subquery that mentions nothing from the query it
 * lands in, `(select "users"."team_id" from "users" where "users"."id" = 5)`, so
 * the database runs it once and treats the result as a constant, and an index on
 * the column it is compared with stays usable under `or` and `not` alike. The
 * user's key is known without asking the database, so a column naming it is the
 * value itself.
 *
 * The key is written into the SQL, escaped by the connection the query runs on,
 * because a {@see StringableExpression} carries no bindings.
 *
 * The table and connection come from a model: the user itself when it is one,
 * else the model named by the `warrant.user_model` config, for an auth provider
 * that returns users that are not models. The key column is the user's auth
 * identifier, which is what the rest of Laravel treats as the user's id.
 */
final readonly class UserRow
{
    public function __construct(
        private Authenticatable $user,
        private QueryFactory $queries,
    ) {
    }

    /**
     * A column of the user's row.
     *
     * @throws BadMethodCallException When there is no model to read the user's
     *   table from, or the user has no key.
     * @throws InvalidArgumentException When the user's table is on another
     *   connection than the query.
     */
    public function column(string $column): StringableExpression
    {
        $model = $this->userModel();
        $key = $this->user->getAuthIdentifier();

        if ($key === null) {
            throw new BadMethodCallException(sprintf(
                'userColumn() reads the user\'s row, but this %s has no key: it is not saved.',
                $this->user::class,
            ));
        }

        if ($model->getConnection()->getName() !== $this->queries->connectionName()) {
            throw new InvalidArgumentException(sprintf(
                'userColumn() cannot reach the user\'s row: %s is on database connection [%s] but the query runs on [%s].',
                $model::class,
                $model->getConnection()->getName(),
                $this->queries->connectionName(),
            ));
        }

        $keyName = $this->user->getAuthIdentifierName();
        $query = $this->queries->newQuery();

        if ($column === $keyName) {
            return new StringableExpression($query->getConnection()->escape($key));
        }

        $table = $model->getTable();

        return new StringableExpression('('.$query
            ->from($table)
            ->select("{$table}.{$column}")
            ->where("{$table}.{$keyName}", $key)
            ->toRawSql().')');
    }

    /**
     * The model whose table holds the user's row: the user itself when it is a
     * model, else a new instance of the one `warrant.user_model` names.
     *
     * @throws BadMethodCallException When neither is there.
     */
    private function userModel(): Model
    {
        if ($this->user instanceof Model) {
            return $this->user;
        }

        $class = config('warrant.user_model');

        if ($class === null) {
            throw new BadMethodCallException(sprintf(
                'userColumn() reads the user\'s row from its table, but %s is not a model. Name your user model in the warrant.user_model config.',
                $this->user::class,
            ));
        }

        return new $class;
    }
}
