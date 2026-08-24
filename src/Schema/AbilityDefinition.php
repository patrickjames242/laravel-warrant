<?php

namespace Warrant\Schema;

/**
 * A schema ability, resolved from an `#[Ability]` constant: its name and the
 * context keys required whenever it is checked. This is the single object the
 * schema's ability resolution returns.
 */
final readonly class AbilityDefinition
{
    /**
     * @param array<int, string> $requiredContext Context keys required when this ability is checked.
     */
    public function __construct(
        public string $name,
        public array $requiredContext = [],
    ) {}
}
