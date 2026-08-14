<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warrant\RuleSyntaxTree\WarrantRule;

/**
 * The context handed to a rule's denial-message closure when that rule denies a
 * singular-target check. Styled after
 * {@see \Warrant\Schema\Conditions\TargetedConditionContext}: the current user,
 * the target being authorized, the specific ability that was denied, the schema
 * the check ran against, the effective check-time context bag, and the rule that
 * caused the denial.
 *
 * `target` is the resolved model when it could be loaded (global scopes removed),
 * or null when the check was made against a bare key that no longer resolves to a
 * row.
 */
final readonly class WarrantDenialContext
{
    /**
     * @param class-string<\Warrant\Schema\WarrantSchema> $schema
     * @param array<string, mixed> $context The effective check-time context.
     */
    public function __construct(
        public Authenticatable $user,
        public ?Model $target,
        public string $ability,
        public string $schema,
        public array $context,
        public WarrantRule $rule,
    ) {
    }
}
