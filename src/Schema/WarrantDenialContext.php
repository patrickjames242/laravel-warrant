<?php

namespace Warrant\Schema;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warrant\Rules\WarrantRule;
use Warrant\WarrantGate;

/**
 * The context handed to a rule's denial-message closure when that `cannot` rule
 * forbids a check. Carries the subject (`user`), the object (`target`), the
 * schema and effective check-time `context`, the {@see WarrantGate} that was
 * asked, the `rule` that forbade it, and `deniedAbilities` — the concrete gate
 * abilities this rule blocked, with any `*` already resolved so the closure never
 * has to expand a wildcard itself.
 *
 * `target` is the resolved model when it could be loaded (global scopes removed),
 * or null for a no-target / capability check.
 */
final readonly class WarrantDenialContext
{
    /**
     * @param class-string<\Warrant\Schema\WarrantSchema> $schema
     * @param array<string, mixed> $context The effective check-time context.
     * @param array<int, string> $deniedAbilities Gate abilities this rule blocked (`*` resolved).
     */
    public function __construct(
        public Authenticatable $user,
        public ?Model $target,
        public string $schema,
        public array $context,
        public WarrantGate $gate,
        public WarrantRule $rule,
        public array $deniedAbilities,
    ) {
    }
}
