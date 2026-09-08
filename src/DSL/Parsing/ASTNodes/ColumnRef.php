<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * A symbolic reference to a database column, written `@column <column>` or
 * `@column <name>.<column>` in a rule (e.g. `@column pay_period_id`,
 * `@column timesheets.pay_period_id`). Like {@see ContextRef} it stays symbolic in
 * the compiled AST — inside {@see ConditionNode::$parameters}, a cross-schema
 * handle's row selector, or a `with` map value — rather than being resolved to a
 * value at parse time.
 *
 * Unlike a context ref its value never depends on the check-time context. It still
 * resolves late, in {@see \Warrant\DSL\Compiling\RuleSetCompiler}, and for a reason
 * the parser cannot help with: which table the reference lands on depends on where
 * the rule was *reached from*. The same text compiles against `docs` at the top of
 * a query, against `d` when the caller wrote `from('docs as d')`, and against `d2`
 * when reached through `can(… for docs(…) as d2)` — see
 * {@see \Warrant\DSL\Compiling\AliasScope}. The compiler resolves it to an
 * {@see \Illuminate\Database\Query\Expression} holding the grammar-wrapped
 * `<qualifier>.<column>` (e.g. `` `timesheets`.`pay_period_id` ``), so a condition
 * can splice it straight into the query builder without it being re-quoted or
 * bound as a value.
 */
readonly class ColumnRef
{
    /**
     * @param string|null $alias The name of the frame whose rows this column
     *   belongs to: a schema key, or an alias a surrounding `check(...)` handle
     *   introduced. Null is the unqualified `@column <column>` form, meaning the
     *   rows the enclosing rule is already about — the common case, and the one
     *   that stays correct wherever the rule is reached from.
     */
    public function __construct(
        public ?string $alias,
        public string $column,
    ) {
    }
}
