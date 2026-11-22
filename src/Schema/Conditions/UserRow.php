<?php

namespace Warrant\Schema\Conditions;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\Support\StringableExpression;

/**
 * The user a compile is for, and the row of the users table that holds them, as
 * a condition reaches it through `userColumn()`. It is one of two things.
 *
 * For a check about one user ({@see forUser()}), it is that user's row. A column
 * of it is a subquery that mentions nothing from the query it lands in,
 * `(select "users"."team_id" from "users" where "users"."id" = 5)`, so the
 * database runs it once and treats the result as a constant, and an index on the
 * column it is compared with stays usable under `or` and `not` alike. The user's
 * key is known without asking the database, so a column naming it is the value
 * itself, written into the SQL and escaped by the connection the query runs on,
 * because a {@see StringableExpression} carries no bindings.
 *
 * The table and connection come from a model: the user itself when it is one,
 * else the model named by the `warrant.user_model` config, for an auth provider
 * that returns users that are not models. The key column is the user's auth
 * identifier, which is what the rest of Laravel treats as the user's id.
 *
 * For a filter over users ({@see forUsersQuery()}), there is no one user: the
 * user is null, and the row is each row of the users query the predicate lands
 * in. A column of it is that row's column, qualified with the name the query
 * gives its rows.
 */
final readonly class UserRow
{
    /**
     * @param Authenticatable|null $user The user the compile is for; null for a
     *   filter over users.
     * @param string|null $usersQualifier The SQL name a users query gives its
     *   rows, which its columns are qualified with; null for a check about one
     *   user.
     */
    private function __construct(
        public ?Authenticatable $user,
        private QueryFactory $queries,
        private ?string $usersQualifier = null,
    ) {
    }

    /**
     * The row of $user, for a check about that one user, compiled into queries
     * $queries makes.
     */
    public static function forUser(Authenticatable $user, QueryFactory $queries): self
    {
        return new self($user, $queries);
    }

    /**
     * Each row of the users query $queries was made from, whose rows go by
     * $usersQualifier, for a filter that answers for every user at once.
     */
    public static function forUsersQuery(string $usersQualifier, QueryFactory $queries): self
    {
        return new self(null, $queries, $usersQualifier);
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
        if ($this->usersQualifier !== null) {
            return new StringableExpression(
                $this->queries->newQuery()->getGrammar()->wrap($this->usersQualifier.'.'.$column),
            );
        }

        /** @var Authenticatable $user A check about one user always has one. */
        $user = $this->user;
        $model = $this->userModel($user);
        $key = $user->getAuthIdentifier();

        if ($key === null) {
            throw new BadMethodCallException(sprintf(
                'userColumn() reads the user\'s row, but this %s has no key: it is not saved.',
                $user::class,
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

        $keyName = $user->getAuthIdentifierName();
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
     * The model whose table holds $user's row: $user itself when it is a model,
     * else a new instance of the one `warrant.user_model` names.
     *
     * @throws BadMethodCallException When neither is there.
     */
    private function userModel(Authenticatable $user): Model
    {
        if ($user instanceof Model) {
            return $user;
        }

        $class = config('warrant.user_model');

        if ($class === null) {
            throw new BadMethodCallException(sprintf(
                'userColumn() reads the user\'s row from its table, but %s is not a model. Name your user model in the warrant.user_model config.',
                $user::class,
            ));
        }

        return new $class;
    }
}
