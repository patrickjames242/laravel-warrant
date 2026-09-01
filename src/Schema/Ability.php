<?php

namespace Warrant\Schema;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class Ability
{
    /**
     * @param array<int, string> $requiredContext Context keys that must be present
     *   whenever this ability is checked. When the ability is named in a yes/no
     *   check (can/canAny/authorize/@can) and a key is missing, the check
     *   throws; when the ability is merely enumerated (selectUserAbilities /
     *   abilities), it is skipped instead.
     */
    public function __construct(public array $requiredContext = []) {}
}
