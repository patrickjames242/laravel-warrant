<?php

namespace Warrant\DSL\Parsing\Validation;

/**
 * The words for a rule entry that names its abilities where it may not, or
 * names none where it must. The validator reports these against rule text, and
 * the expander rejects the same entries in any rule set that reaches it, so
 * both say them here, in one way.
 */
final class AbilityNamingMessages
{
    public static function blockNamesNone(): string
    {
        return 'An ability block names at least one ability.';
    }

    public static function blockInsideBlock(): string
    {
        return 'An ability block may not contain another; the enclosing block already names the abilities.';
    }

    /**
     * @param string $entry 'A clause' or 'An @include'.
     */
    public static function namedInsideBlock(string $entry): string
    {
        return sprintf('%s inside an ability block may not name abilities; the block header already names them.', $entry);
    }

    public static function unnamedOutsideBlock(string $schemaKey): string
    {
        return sprintf(
            'Every clause and @include outside an ability block names the abilities it applies to; the rule set '
                .'for [%s] holds one that names none.',
            $schemaKey,
        );
    }

    /**
     * @param string $entry 'This clause', 'This @include' or 'An ability block'.
     * @param bool $names Whether that entry names abilities.
     */
    public static function namingDisagrees(string $entry, bool $names): string
    {
        return sprintf(
            '%s %s, but the rules before it %s; rules with no `for` header either all name their abilities '
                .'or all leave them to be named where the rules are placed.',
            $entry,
            $names ? 'names abilities' : 'names none',
            $names ? 'name none' : 'name theirs',
        );
    }
}
