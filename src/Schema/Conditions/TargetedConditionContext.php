<?php

namespace Warrant\Schema\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The evaluation context passed to a `#[TargetedCondition]` method: the current
 * user, the where-clause builder the condition constrains, the SQL id of the
 * target row being evaluated, the resolved DSL arguments, and the check-time
 * context bag.
 *
 * `targetSqlId` is guaranteed present — a targeted condition is never dispatched
 * without a row to evaluate against.
 *
 * `context` is the effective check-time context (after `defaultContext()` merge),
 * available to every condition whether or not the rule passed a value via
 * `@context`. Read it directly for an ambient frame: `$c->context['tenant_id']`.
 */
final readonly class TargetedConditionContext
{
    /**
     * @param array<int, mixed> $arguments The resolved DSL arguments.
     * @param array<string, mixed> $context The effective check-time context.
     */
    public function __construct(
        public Authenticatable $user,
        public Builder $query,
        public string $targetSqlId,
        public array $arguments = [],
        public array $context = [],
    ) {
    }
}
