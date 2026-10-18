<?php

namespace Warrant\DSL\Parsing\Validation;

/**
 * The words for a rule of the language broken by text the parser can read: an
 * entry naming its abilities where it may not, or naming none where it must; a
 * denial message where none belongs, or one that cannot be one; an `@include`
 * naming a template the schema does not declare, or giving it too few
 * arguments. The validator reports these against rule text, and the expander
 * rejects the same in any rule set that reaches it, so both say them here, in
 * one way.
 */
final class LanguageRuleMessages
{
    /**
     * @param string $schemaKey The schema the `@include` is written for.
     * @param string $templateKey The template it names.
     */
    public static function unknownTemplate(string $schemaKey, string $templateKey): string
    {
        return sprintf('Schema [%s] declares no rule template [%s], named by an @include.', $schemaKey, $templateKey);
    }

    /**
     * @param string $templateKey The template the `@include` names.
     * @param int $required How many arguments the template requires.
     * @param int $supplied How many the `@include` gives it.
     */
    public static function tooFewTemplateArguments(string $templateKey, int $required, int $supplied): string
    {
        return sprintf(
            'Rule template [%s] requires %d argument(s), but the @include supplies %d.',
            $templateKey,
            $required,
            $supplied,
        );
    }

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

    /**
     * @param string|null $schemaKey The schema the rules are for; null when no
     *   schema is known.
     */
    public static function unnamedOutsideBlock(?string $schemaKey): string
    {
        return $schemaKey === null
            ? 'Every clause and @include outside an ability block names the abilities it applies to; these rules '
                .'hold one that names none.'
            : sprintf(
                'Every clause and @include outside an ability block names the abilities it applies to; the rule set '
                    .'for [%s] holds one that names none.',
                $schemaKey,
            );
    }

    /**
     * @param string $entry 'This clause', 'This @include' or 'An ability block'.
     */
    public static function namedWhereGeneric(string $entry): string
    {
        return sprintf(
            '%s names abilities, but these rules leave every one to be named where they are placed, by the '
                .'@include that places them.',
            $entry,
        );
    }

    public static function messageOnCanClause(): string
    {
        return "'because' may only follow a 'they cannot ...' clause, not 'they can ...'.";
    }

    public static function messageIsNotText(): string
    {
        return "A denial message after 'because' is a quoted string or a binding (:name or ?). "
            .'@context is not allowed here.';
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
