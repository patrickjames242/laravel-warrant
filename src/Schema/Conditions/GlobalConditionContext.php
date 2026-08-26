<?php

namespace Warrant\Schema\Conditions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The evaluation context passed to a `#[GlobalCondition]` method: the current
 * user, the where-clause builder the condition may constrain, the resolved DSL
 * arguments, and the check-time context bag.
 *
 * There is no target row — a global condition is about the user or the ambient
 * context, not a specific record. It may mutate the query and return it, or
 * short-circuit by returning a boolean.
 *
 * `context` is the effective check-time context (after `defaultContext()` merge),
 * available whether or not the rule passed a value via `@context`. Read it
 * directly for an ambient frame: `$c->context['tenant_id']`.
 *
 * The DSL arguments are exposed as `arguments`, but a condition may also declare
 * them as method parameters after this context object — parameter #2 receives
 * `arguments[0]`, #3 receives `arguments[1]`, and so on — with the full list still
 * available here.
 */
final readonly class GlobalConditionContext
{
    /**
     * @param array<int, mixed> $arguments The resolved DSL arguments.
     * @param array<string, mixed> $context The effective check-time context.
     */
    public function __construct(
        public Authenticatable $user,
        public Builder $query,
        public array $arguments = [],
        public array $context = [],
    ) {
    }
}
