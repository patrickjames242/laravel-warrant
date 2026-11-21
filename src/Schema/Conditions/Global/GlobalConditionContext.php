<?php

namespace Warrant\Schema\Conditions\Global;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Conditions\UserRow;
use Warrant\Support\StringableExpression;

/**
 * The evaluation context passed to a `#[GlobalCondition]` method: the current
 * user, the where-clause builder the condition may constrain, the resolved DSL
 * arguments, the check-time context bag, and {@see userColumn()}, a column of the
 * user's own row.
 *
 * There is no target row — a global condition is about the user or the ambient
 * context, not a specific record. It may mutate the query and return it,
 * short-circuit by returning a boolean, or return null to answer unknown, which
 * neither grants nor lifts a deny.
 *
 * `context` is the effective check-time context (after `defaultContext()` merge),
 * available whether or not the rule passed a value via `@context`. Read it
 * directly for an ambient frame: `$c->context['tenant_id']`.
 *
 * The DSL arguments are exposed as `arguments`, and a condition declares them
 * as method parameters after this context object — parameter #2 receives
 * `arguments[0]`, #3 receives `arguments[1]`, and so on — with the full list still
 * available here. A rule may pass no more arguments than there are parameters,
 * so a condition taking any number declares its last one variadic.
 */
final readonly class GlobalConditionContext
{
    /**
     * @param UserRow $userRow The user's row, which {@see userColumn()} reads.
     * @param array<int, mixed> $arguments The resolved DSL arguments.
     * @param array<string, mixed> $context The effective check-time context.
     */
    public function __construct(
        public Authenticatable $user,
        private UserRow $userRow,
        public Builder $query,
        public array $arguments = [],
        public array $context = [],
    ) {
    }

    /**
     * A column of the user's row, for a predicate about the user written in SQL:
     *
     *     return $c->query->where($c->userColumn('role'), 'admin');
     *
     * See {@see UserRow} for the SQL it stands for.
     */
    public function userColumn(string $column): StringableExpression
    {
        return $this->userRow->column($column);
    }
}
