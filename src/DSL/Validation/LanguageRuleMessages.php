<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\Resolution\GlobalSchemaId;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;

/**
 * The words for a rule of the language broken by text the parser can read: an
 * entry naming its abilities where it may not, or naming none where it must; a
 * denial message where none belongs, or one that cannot be one; a binding
 * standing for abilities whose value names none; an ability written twice, or
 * `*` beside another; a condition or an `@include`'s template that no schema
 * the name is looked up in declares, or a condition or template given too few
 * arguments or too many. The validator reports these against rule text, and the
 * expander rejects the same in any rule set that reaches it, so both say them
 * here, in one way.
 */
final class LanguageRuleMessages
{
    /**
     * @param ISchemaId $writtenIn The schema the `@include` was written in.
     * @param string $templateKey The template it names.
     */
    public static function unknownTemplate(ISchemaId $writtenIn, string $templateKey): string
    {
        return sprintf('Rule template [%s], named by an @include, %s', $templateKey, self::declaredByNone($writtenIn));
    }

    /**
     * @param ISchemaId $writtenIn The schema the condition's name was written in.
     * @param string $conditionKey The condition it names.
     */
    public static function unknownCondition(ISchemaId $writtenIn, string $conditionKey): string
    {
        return sprintf('Condition [%s] %s', $conditionKey, self::declaredByNone($writtenIn));
    }

    /**
     * That none of the schemas a name written in $writtenIn is looked up in
     * declares it: the schema itself and the globals, or, for text a global
     * schema answered with, the globals alone.
     */
    private static function declaredByNone(ISchemaId $writtenIn): string
    {
        return match (true) {
            $writtenIn instanceof KeyedSchemaId => sprintf(
                'is declared by neither schema [%s] nor any global schema.',
                $writtenIn->key,
            ),
            $writtenIn instanceof GlobalSchemaId => sprintf(
                'is declared by no global schema; the text global schema [%s] answers with looks only in the global '
                    .'schemas.',
                $writtenIn->class,
            ),
        };
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

    /**
     * @param string $templateKey The template the `@include` names.
     * @param int $max How many arguments the template takes at most.
     * @param int $supplied How many the `@include` gives it.
     */
    public static function tooManyTemplateArguments(string $templateKey, int $max, int $supplied): string
    {
        return sprintf(
            'Rule template [%s] takes at most %d argument(s), but the @include supplies %d.',
            $templateKey,
            $max,
            $supplied,
        );
    }

    /**
     * @param string $conditionKey The condition the rule names.
     * @param int $max How many arguments the condition takes at most.
     * @param int $supplied How many the rule gives it.
     */
    public static function tooManyConditionArguments(string $conditionKey, int $max, int $supplied): string
    {
        return sprintf(
            'Condition [%s] takes at most %d argument(s), but the rule supplied %d.',
            $conditionKey,
            $max,
            $supplied,
        );
    }

    public static function blockNamesNone(): string
    {
        return 'An ability block names at least one ability.';
    }

    public static function whenBlockEmpty(): string
    {
        return 'A when block holds at least one rule; this one guards nothing.';
    }

    public static function otherwiseEmpty(): string
    {
        return "An otherwise body holds at least one rule; leave 'otherwise' off instead.";
    }

    public static function blockInsideBlock(): string
    {
        return 'An ability block may not contain another; the enclosing block already names the abilities.';
    }

    /**
     * @param string $entry 'A can/cannot clause' or 'An @include'.
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
            ? 'Every can/cannot clause and @include outside an ability block names the abilities it applies to; these rules '
                .'hold one that names none.'
            : sprintf(
                'Every can/cannot clause and @include outside an ability block names the abilities it applies to; the rules '
                    .'for [%s] hold one that names none.',
                $schemaKey,
            );
    }

    /**
     * @param string $entry 'This can/cannot clause', 'This @include' or 'An ability block'.
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
        return "A denial message after 'because' is a quoted string or a binding (:name). "
            .'@context is not allowed here.';
    }

    /**
     * @param string $entry 'This can/cannot clause', 'This @include' or 'An ability block'.
     * @param bool $names Whether that entry names abilities.
     */
    public static function namingDisagrees(string $entry, bool $names): string
    {
        return sprintf(
            '%s %s, but the rules before it %s; rules either all name their abilities or all leave them to be '
                .'named where the rules are placed.',
            $entry,
            $names ? 'names abilities' : 'names none',
            $names ? 'name none' : 'name theirs',
        );
    }

    /**
     * @param string $binding The binding's name, without its colon.
     * @param string $got What its value is instead, as words for the author.
     */
    public static function abilityListBindingValue(string $binding, string $got): string
    {
        return sprintf(
            'The binding [:%s] stands in an ability list, so its value is an ability name or a non-empty list '
                .'of them; got %s.',
            $binding,
            $got,
        );
    }

    /**
     * @param string $binding The binding's name, without its colon.
     * @param string $got What its value is instead, as words for the author.
     */
    public static function oneAbilityBindingValue(string $binding, string $got): string
    {
        return sprintf(
            'The binding [:%s] stands for the one ability a can(...) reference asks about, so its value is an '
                .'ability name; got %s.',
            $binding,
            $got,
        );
    }

    /**
     * @param string $written The ability or binding as words for the author,
     *   such as 'Ability [view]' or 'Binding [:more]'.
     * @param string $among The abilities it is written among, such as 'this rule grants'.
     */
    public static function abilityWrittenTwice(string $written, string $among): string
    {
        return sprintf('%s is written more than once among the abilities %s; write it once.', $written, $among);
    }

    /**
     * @param string $among The abilities `*` is written among, such as 'this rule grants'.
     */
    public static function starBesideAbilities(string $among): string
    {
        return sprintf('`*` stands for every ability, so nothing else is written beside it among the abilities %s.', $among);
    }
}
