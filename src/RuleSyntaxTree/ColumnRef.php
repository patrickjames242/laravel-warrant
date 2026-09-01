<?php

namespace Warrant\RuleSyntaxTree;

/**
 * A symbolic reference to a database column, written `@column <schema>.<column>`
 * in a rule (e.g. `@column timesheets.pay_period_id`). Like {@see ContextRef} it
 * stays symbolic in the compiled AST — inside {@see ConditionNode::$parameters},
 * a cross-schema handle's row selector, or a `with` map value — rather than being
 * resolved to a value at parse time.
 *
 * Unlike a context ref its value never depends on the check-time context: it is
 * static. It still resolves late, in {@see RuleSetCompiler}, because turning the
 * schema key into the model's real table name needs the schema registry
 * ({@see \Warrant\Registry\SchemaRegistry}) and quoting the identifier needs the query's
 * grammar — neither of which the parser has. The compiler resolves it to an
 * {@see \Illuminate\Database\Query\Expression} holding the grammar-wrapped
 * `<table>.<column>` (e.g. `` `timesheets`.`pay_period_id` ``), so a condition can
 * splice it straight into the query builder without it being re-quoted or bound
 * as a value.
 */
readonly class ColumnRef
{
    public function __construct(
        public string $schemaKey,
        public string $column,
    ) {
    }
}
