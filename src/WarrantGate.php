<?php

namespace Warrant;

/**
 * The permission requirement a check was made against: the requested abilities
 * and the match mode that combines them (`ALL` — every ability; `ANY` — at least
 * one). This is the "what was asked" half of a denial, deliberately independent
 * of the subject (user) and the object (target): the same gate can be checked
 * against any row.
 *
 * `abilities` is the normalized requested set and never contains `*`.
 */
final readonly class WarrantGate
{
    /**
     * @param array<int, string> $abilities
     */
    public function __construct(
        public array $abilities,
        public AbilityMatchMode $matchMode,
    ) {
    }
}
