<?php

namespace Warrant\Schema;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The evaluation context passed to a `#[ComputedAbility]` method: the current
 * user and the effective check-time context (after `defaultContext()` merge and
 * required-key enforcement). There is no target row and no query — a computed
 * ability answers a no-target question with plain PHP.
 */
final readonly class ComputedAbilityContext
{
    /**
     * @param array<string, mixed> $context The effective check-time context.
     */
    public function __construct(
        public Authenticatable $user,
        public array $context = [],
    ) {}
}
