<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * The error for an entry in rules with no `for` header that does not name its
 * abilities the way the first entry did; see {@see AbilityNaming}. Used by
 * parsers of the grammar, which supply the error helper this reports with.
 */
trait ReportsAbilityNamingMismatches
{
    /**
     * Error for an entry that names abilities when the first entry named none,
     * or names none when the first named theirs.
     *
     * @param bool $names Whether the entry being read names abilities.
     * @param string $entry The entry being read, for the message.
     */
    private function abilityNamingMismatchError(bool $names, string $entry): WarrantSyntaxException
    {
        return $this->errorAtCurrent(sprintf(
            '%s %s, but the rules before it %s; rules with no `for` header either all name their abilities '
                .'or all leave them to be named where the rules are placed.',
            $entry,
            $names ? 'names abilities' : 'names none',
            $names ? 'name none' : 'name theirs',
        ));
    }
}
