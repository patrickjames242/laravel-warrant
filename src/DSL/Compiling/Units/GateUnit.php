<?php

namespace Warrant\DSL\Compiling\Units;

use Warrant\Rules\WarrantRuleSet;
use Warrant\WarrantGate;

/**
 * A whole gate: the requested abilities plus the match mode that combines them.
 *
 * `ALL` ANDs the abilities' predicates, `ANY` ORs them. Compiling the gate as one
 * unit — rather than one ability at a time with the caller joining the results —
 * is what lets a constant cross the ability boundary, so an `ANY` gate over an
 * unconditionally granted ability is simply `true` and the other abilities never
 * reach the SQL at all.
 */
final readonly class GateUnit implements CompilationUnit
{
    public function __construct(
        public WarrantGate $gate,
        public WarrantRuleSet $ruleSet,
    ) {
    }
}
