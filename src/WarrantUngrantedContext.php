<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The context handed to a schema's {@see \Warrant\Schema\WarrantSchema::ungrantedDenialMessage()}
 * hook when a check fails for lack of a grant — nothing forbade the user, but
 * nothing granted them either, so there is no rule to name (contrast
 * {@see WarrantDenialContext}, which always carries the responsible `rule`).
 *
 * Carries the {@see WarrantGate} that was asked and `ungrantedAbilities` — the
 * concrete gate abilities that had no grant. Under `ANY` that is the whole gate;
 * under `ALL` it is the missing subset (the ones the user was lacking).
 *
 * `target` is the resolved model, or null for a no-target / capability check.
 */
final readonly class WarrantUngrantedContext
{
    /**
     * @param class-string<\Warrant\Schema\WarrantSchema> $schema
     * @param array<string, mixed> $context The effective check-time context.
     * @param array<int, string> $ungrantedAbilities Gate abilities with no grant.
     */
    public function __construct(
        public Authenticatable $user,
        public ?Model $target,
        public string $schema,
        public array $context,
        public WarrantGate $gate,
        public array $ungrantedAbilities,
    ) {
    }
}
