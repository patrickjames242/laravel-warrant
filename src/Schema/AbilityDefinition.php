<?php

namespace Warrant\Schema;

/**
 * A schema ability, resolved from an `#[Ability]` constant or a
 * `#[ComputedAbility]` method: its name, the context keys required whenever it
 * is checked, whether it is computed (imperative, no SQL form), and — for a
 * computed ability — the schema method that answers it plus the ordered plan
 * for calling it. This is the single object the schema's ability resolution
 * returns.
 */
final readonly class AbilityDefinition
{
    /**
     * @param array<int, string> $requiredContext Context keys required when this ability is checked.
     * @param string|null $method The schema method answering a computed ability; null for a declared one.
     * @param array<int, array{kind: string, key?: string, optional?: bool, default?: mixed}> $parameterBindings
     *        Ordered call plan for a computed ability's method, one descriptor per parameter — `user`,
     *        `context_object`, or a `context` key injected by name. Empty for a compiled ability.
     */
    public function __construct(
        public string $name,
        public array $requiredContext = [],
        public bool $computed = false,
        public ?string $method = null,
        public array $parameterBindings = [],
    ) {}
}
