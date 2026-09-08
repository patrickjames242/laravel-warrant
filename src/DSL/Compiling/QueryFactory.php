<?php

namespace Warrant\DSL\Compiling;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

/**
 * A source of blank query builders, plus the connection, grammar and row-name
 * metadata that go with them.
 *
 * The compiler is handed a query builder by its caller, but it never appends to
 * it — it uses it to make *new* builders on the same connection, to compare
 * connection names, to quote identifiers with the right grammar, and to read the
 * name the host query's rows answer to ({@see rowQualifier()}). That is the whole
 * of the dependency, and this class is it: the builder it was built from is
 * private, so the only thing reachable through it is a fresh query.
 *
 * The guarantee is narrow and worth stating plainly. This protects the specific
 * builder the caller passed in. Every builder the compiler *creates* is still
 * handed out raw — to {@see \Warrant\DSL\ConditionResolver::applyCondition()},
 * which mutates it by contract, and to callers through
 * {@see CompilationResult::toQuery()}. What the wrapper buys is not safety from
 * a bug that exists today, but a name for what the compiler actually needs, so
 * that questions like "which connection does a cross-schema reference run on?"
 * have an obvious place to be answered.
 */
final readonly class QueryFactory
{
    private function __construct(private Builder $prototype)
    {
    }

    /**
     * Wrap an existing query builder. Only its connection, grammar, processor and
     * `from` are ever read; its wheres and bindings are irrelevant here and are
     * never copied into anything this hands out.
     */
    public static function for(Builder $query): self
    {
        return new self($query);
    }

    /**
     * A factory for a connection with no table in play — the no-target compile,
     * where predicates are evaluated without a row.
     */
    public static function forConnection(Connection $connection): self
    {
        return new self($connection->query());
    }

    /**
     * A fresh builder on the same connection: no table, no wheres, no bindings.
     */
    public function newQuery(): Builder
    {
        return $this->prototype->newQuery();
    }

    /**
     * The name of the connection these queries run on. Used to reject a
     * cross-schema reference to a schema living on a different connection, whose
     * table would not be there when the SQL executes.
     */
    public function connectionName(): string
    {
        return $this->prototype->getConnection()->getName();
    }

    /**
     * The connection's driver name (`pgsql`, `mysql`, `mariadb`, `sqlite`), for
     * the places where emitted SQL has to differ per driver.
     */
    public function driverName(): string
    {
        return $this->prototype->getConnection()->getDriverName();
    }

    /**
     * The SQL name the host query's rows answer to — the qualifier a predicate
     * about one of those rows has to be written against.
     *
     * `from('docs')` gives `docs` and `from('docs as d')` gives `d`, matching
     * Laravel's own aliasing: a table prefix, if one is configured, is applied to
     * an alias too ({@see \Illuminate\Database\Grammar::wrapAliasedTable()}), so
     * the unprefixed name returned here is exactly what {@see wrap()} expects.
     *
     * Null when the prototype has no `from` at all (a no-target compile, which has
     * no row anyway) or when it is a raw {@see Expression}, whose rows have no name
     * this can read out. Either way the caller falls back to the schema model's own
     * table.
     */
    public function rowQualifier(): ?string
    {
        $from = $this->prototype->from;

        if (! is_string($from) || trim($from) === '') {
            return null;
        }

        // Same split Laravel's grammar uses, so `AS` and odd spacing agree with it.
        $segments = preg_split('/\s+as\s+/i', trim($from), 2);
        $qualifier = trim(end($segments));

        return $qualifier === '' ? null : $qualifier;
    }

    /**
     * Quote an identifier with this connection's grammar, so it is emitted
     * verbatim rather than bound as a value.
     */
    public function wrap(string $identifier): Expression
    {
        return new Expression($this->prototype->getGrammar()->wrap($identifier));
    }
}
