<?php

namespace Warrant\Schema\Abilities;

/**
 * A schema that declares abilities: the constants carrying an
 * {@see IDeclaresAbility} attribute, normally `#[Ability]`.
 */
interface IProvidesAbilities
{
    /**
     * @return list<AbilityDefinition> The schema's abilities, in declaration order.
     */
    public function abilities(): array;

    /**
     * The ability named $name, or null when the schema declares none.
     */
    public function ability(string $name): ?AbilityDefinition;
}
