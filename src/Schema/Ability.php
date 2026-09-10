<?php

namespace Warrant\Schema;

use Attribute;

/**
 * Marks a schema class constant as one of the schema's abilities, the constant's
 * value being the ability's name.
 *
 * This is the ability attribute Warrant ships, and the one a schema uses unless
 * it has a reason not to. It states its required context as a literal list. A
 * schema that would rather derive that list can declare an attribute of its own
 * implementing {@see DeclaresAbility}, which the engine recognizes identically.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class Ability implements DeclaresAbility
{
    /**
     * @param array<int, string> $requiredContext Context keys that must be present
     *   whenever this ability is checked. When the ability is named in a yes/no
     *   check (can/canAny/authorize/@can) and a key is missing, the check
     *   throws; when the ability is merely enumerated (selectUserAbilities /
     *   abilities), it is skipped instead.
     */
    public function __construct(public array $requiredContext = []) {}

    /**
     * @return array<int, string>
     */
    public function requiredContext(): array
    {
        return $this->requiredContext;
    }
}
