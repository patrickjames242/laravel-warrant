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
     * @param string $table The target row's table (or query alias).
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
