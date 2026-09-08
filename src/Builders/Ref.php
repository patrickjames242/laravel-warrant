<?php

namespace Warrant\Builders;

use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;

/**
 * Factories for the DSL's three symbolic references — `@context`, `@column` and
 * `@sql` — for use anywhere the builder takes an argument value: a condition
 * parameter, a cross-schema row selector, or a `with` map value.
 *
 * They return the same objects the parser produces, and like the parser they stay
 * symbolic in the AST: their values are filled late, by
 * {@see \Warrant\DSL\Compiling\RuleSetCompiler} — a context ref per check, a column
 * ref against the registry and the query's grammar, a SQL ref verbatim.
 *
 * ```php
 * ->if('in_period', [Ref::context('year')])
 * ->andIfCan('manage', 'departments', Ref::context('department_id'))
 * ->orIfCheck('is_open', 'pay_periods', Ref::column('pay_period_id'))
 * ```
 */
final class Ref
{
    /** A check-time context value — `@context <key>` in the DSL. */
    public static function context(string $key): ContextRef
    {
        return new ContextRef($key);
    }

    /**
     * A database column — `@column <column>` or `@column <name>.<column>` in the
     * DSL. One argument is the column, on whatever rows the rule is already about;
     * two name a frame and then the column.
     *
     * ```php
     * Ref::column('pay_period_id')                // @column pay_period_id
     * Ref::column('timesheets', 'pay_period_id')  // @column timesheets.pay_period_id
     * ```
     *
     * Prefer one argument. The frame a rule is about is decided per compile — the
     * host query's own table or alias, or a cross-schema hop's — so a reference
     * that names nothing cannot name the wrong thing, while a qualified one is a
     * claim that has to keep being true wherever the rule is reached from. Reach
     * for the two-argument form only where a rule really can see more than one
     * frame: a `check(...)` predicate correlating its target with its caller.
     *
     * Nothing is resolved here. Which table a name refers to is not knowable until
     * compile time, and both validation and compilation reject a name that is not
     * in scope with a precise message — which keeps this a pure value factory.
     */
    public static function column(string $columnOrFrame, ?string $column = null): ColumnRef
    {
        return $column === null
            ? new ColumnRef(null, $columnOrFrame)
            : new ColumnRef($columnOrFrame, $column);
    }

    /**
     * A raw SQL fragment — `@sql "<sql>"` in the DSL. The body is emitted verbatim,
     * so table scoping and injection are entirely the rule author's responsibility.
     */
    public static function sql(string $sql): SqlRef
    {
        return new SqlRef($sql);
    }

    private function __construct()
    {
    }
}
