<?php

namespace Warrant;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class Ability
{
    /**
     * @param array<int, string> $requires Context keys that must be present
     *   whenever this ability is checked. When the ability is named in a yes/no
     *   check (userHasAbilities/authorize/@can) and a key is missing, the check
     *   throws; when the ability is merely enumerated (selectUserAbilities /
     *   getUserAbilities), it is skipped instead.
     */
    public function __construct(public array $requires = []) {}
}
