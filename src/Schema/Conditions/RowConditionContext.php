<?php

namespace Warrant\Schema\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The evaluation context passed to a `#[RowCondition]` method: the current user,
 * the where-clause builder the condition constrains, the resolved DSL arguments,
 * the check-time context bag, and {@see row()} — the qualified SQL identity of
 * the target row being evaluated.
 *
 * `query` is an Eloquent builder over the schema's model, so a condition can
 * spend a scope the model already defines rather than restating its SQL:
 *
 *     return $c->query->isAlreadyPaidByPayroll();
 *
 * It wraps the where clause the compiler reads back, so a scope's constraints
 * land exactly where a hand-written `where()` would. The model's global scopes
 * are deliberately absent: a condition answers the question it was asked. And
 * the clause is a bare where group, so a scope may only add wheres — one that
 * joins or groups is rejected, and wants a correlated `whereExists` instead.
 *
 * A scope speaks the model's own table, which is not always what the row is
 * called here — see {@see row()}. Retargeting the model at the alias is not the
 * fix: `getTable()` would then lie to any scope reading it to build a subquery.
 * So a scope reached through an alias names a table the query does not have,
 * failing as it would anywhere else a scope meets an alias, and a condition that
 * must follow the row's name builds its predicate from `row()` instead.
 *
 * A row is always present — a row condition is never dispatched without a row to
 * evaluate against, so `row()` is guaranteed to resolve.
 *
 * The row's *instance* is a different question, and that is `model`. It holds the
 * loaded target when the check named one (`can('update', $document)`), and null
 * whenever the compile covers more than one row — filtering a query, listing
 * per-row abilities, or a check given only a key. A condition handed the model may
 * answer in PHP by returning a bool, which folds away without reaching the
 * database; it must still return its predicate when `model` is null, since that is
 * the only form that can filter. The two branches have to agree, or the same rule
 * decides differently depending on how it was reached:
 *
 *     if ($c->model !== null) {
 *         return $c->model->owner_id === $c->user->getAuthIdentifier();
 *     }
 *
 *     return $c->query->whereRaw("{$c->row('owner_id')} = ?", [$c->user->getAuthIdentifier()]);
 *
 * A condition that cannot settle its question either way returns null instead,
 * answering unknown: it neither grants nor lifts a deny. One that does must leave
 * `query` untouched, since a null return is also what a missing `return`
 * statement produces, and the two mean opposite things.
 *
 * `context` is the effective check-time context (after `defaultContext()` merge),
 * available to every condition whether or not the rule passed a value via
 * `@context`. Read it directly for an ambient frame: `$c->context['tenant_id']`.
 *
 * The DSL arguments are exposed as `arguments`, but a condition may also declare
 * them as method parameters after this context object — parameter #2 receives
 * `arguments[0]`, #3 receives `arguments[1]`, and so on — with the full list still
 * available here.
 */
final readonly class RowConditionContext
{
    /**
     * @param string $table The name the target row answers to in SQL: the schema
     *   model's table, or the alias standing in for it — the host query's own
     *   (`from('docs as d')`), or a cross-schema hop's (`can(… for docs(…) as d2)`).
     *   Always the right thing to qualify a column with, which is why {@see row()}
     *   is the only supported way to build one.
     * @param string $keyColumn The target row's primary key column.
     * @param array<int, mixed> $arguments The resolved DSL arguments.
     * @param array<string, mixed> $context The effective check-time context.
     * @param Model|null $model The loaded target row, or null when the condition is
     *   compiling against more than one row. See the class docblock.
     */
    public function __construct(
        public Authenticatable $user,
        public Builder $query,
        public string $table,
        public string $keyColumn,
        public array $arguments = [],
        public array $context = [],
        public ?Model $model = null,
    ) {
    }

    /**
     * The qualified SQL identity of the target row.
     *
     * With no argument, returns the row's qualified key (e.g. `timesheets.id`).
     * Pass a column name to qualify a different column of the target table
     * (e.g. `row('owner_id')` → `timesheets.owner_id`), so a condition never has
     * to hand-concatenate the table prefix.
     */
    public function row(?string $column = null): string
    {
        return $this->table . '.' . ($column ?? $this->keyColumn);
    }
}
