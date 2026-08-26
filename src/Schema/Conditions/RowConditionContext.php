<?php

namespace Warrant\Schema\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The evaluation context passed to a `#[RowCondition]` method: the current user,
 * the where-clause builder the condition constrains, the resolved DSL arguments,
 * the check-time context bag, and {@see row()} — the qualified SQL identity of
 * the target row being evaluated.
 *
 * A row is always present — a row condition is never dispatched without a row to
 * evaluate against, so `row()` is guaranteed to resolve.
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
     */
    public function __construct(
        public Authenticatable $user,
        public Builder $query,
        public string $table,
        public string $keyColumn,
        public array $arguments = [],
        public array $context = [],
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
