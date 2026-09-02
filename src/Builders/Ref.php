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
 * ->orIfCheck('is_open', 'pay_periods', Ref::column('timesheets', 'pay_period_id'))
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
     * A database column — `@column <schema>.<column>` in the DSL.
     *
     * The schema key is explicit, as it is in the DSL: it may name the owning
     * schema, correlating against the row being checked, or another schema. It is
     * deliberately not resolved here — validation and compilation already reject an
     * unknown or model-less key with a precise message, which keeps this a pure
     * value factory.
     */
    public static function column(string $schemaKey, string $column): ColumnRef
    {
        return new ColumnRef($schemaKey, $column);
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
