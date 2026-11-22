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
 * arguments or too many; a constant no schema the name is looked up in
 * declares, or one that cannot stand where it is written. The validator reports these against rule text, and the
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
     * @param ISchemaId $writtenIn The schema the constant's name was written in.
     * @param string $name The constant it names.
     */
    public static function unknownConstant(ISchemaId $writtenIn, string $name): string
    {
        return sprintf('Constant [%s] %s', $name, self::declaredByNone($writtenIn));
    }

    /**
     * @param string $schemaKey The schema the prefix names.
     * @param string $name The constant written after it.
     */
    public static function constantNotDeclaredBy(string $schemaKey, string $name): string
    {
        return sprintf(
            'Constant [%s.%s]: schema [%s] declares no constant [%s]. A schema prefix reaches that schema\'s own '
                .'constants alone, never a global schema\'s.',
            $schemaKey,
            $name,
            $schemaKey,
            $name,
        );
    }

    /**
     * @param string $schemaKey The key written before the dot.
     * @param string $written The constant reference as written.
     */
    public static function unknownConstantSchema(string $schemaKey, string $written): string
    {
        return sprintf('Constant [%s] names unknown schema [%s].', $written, $schemaKey);
    }

    /**
     * @param string $written The constant reference as written.
     * @param mixed $value Its value.
     */
    public static function sqlConstantNotText(string $written, mixed $value): string
    {
        return sprintf('An @sql constant must be a string, but [%s] is %s.', $written, get_debug_type($value));
    }

    /**
     * @param string $written The constant reference as written.
     * @param mixed $value Its value.
     */
    public static function messageConstantNotText(string $written, mixed $value): string
    {
        return sprintf('A denial message must be a string, but constant [%s] is %s.', $written, get_debug_type($value));
    }

    /**
     * The declared names $written could have meant, as a question, or null when
     * none is close.
     *
     * @param string $written The name as written.
     * @param iterable<string> $declared The names of its kind it could have meant.
     */
    public static function didYouMean(string $written, iterable $declared): ?string
    {
        $closest = ClosestNames::to($written, $declared);

        return $closest === [] ? null : sprintf('Did you mean %s?', self::eitherOf($closest));
    }

    /**
     * What `its $written` could have named on schema $schemaKey: the row relations
     * it declares, the closest one, and where a row relation is declared.
     *
     * @param string $written The relation's name as written.
     * @param string $schemaKey The schema the rule is for.
     * @param string $schemaClass Its class.
     * @param list<string> $relations The row relations it declares.
     */
    public static function rowRelationsOf(string $written, string $schemaKey, string $schemaClass, array $relations): string
    {
        $declared = match (count($relations)) {
            0 => sprintf('Schema [%s] declares no row relations.', $schemaKey),
            1 => sprintf('Schema [%s] declares one row relation: [%s].', $schemaKey, $relations[0]),
            default => sprintf('Schema [%s] declares the row relations %s.', $schemaKey, self::allOf($relations)),
        };
        $class = substr((string) strrchr('\\'.$schemaClass, '\\'), 1);

        return implode(' ', array_filter([
            $declared,
            self::didYouMean($written, $relations),
            sprintf('A row relation is a #[RowRelation] method on %s.', $class),
        ]));
    }

    /**
     * @param list<string> $names
     */
    private static function eitherOf(array $names): string
    {
        return self::listed($names, 'or');
    }

    /**
     * @param list<string> $names
     */
    private static function allOf(array $names): string
    {
        return self::listed($names, 'and');
    }

    /**
     * $names bracketed, as `[a]`, `[a] or [b]`, or `[a], [b] or [c]`.
     *
     * @param list<string> $names
     */
    private static function listed(array $names, string $conjunction): string
    {
        $bracketed = array_map(static fn (string $name): string => "[{$name}]", $names);
        $last = array_pop($bracketed);

        return $bracketed === [] ? (string) $last : implode(', ', $bracketed)." {$conjunction} {$last}";
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
        return "A denial message after 'because' is a quoted string, a binding (:name) or a string constant. "
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
     * @param string $written The binding or constant as words for the author,
     *   such as 'binding [:more]' or 'constant [editors]'.
     * @param string $got What its value is instead, as words for the author.
     */
    public static function abilityListValue(string $written, string $got): string
    {
        return sprintf(
            'The %s stands in an ability list, so its value is an ability name or a non-empty list of them; '
                .'got %s.',
            $written,
            $got,
        );
    }

    /**
     * @param string $written The binding or constant as words for the author,
     *   such as 'binding [:more]' or 'constant [editors]'.
     * @param string $got What its value is instead, as words for the author.
     */
    public static function oneAbilityValue(string $written, string $got): string
    {
        return sprintf(
            'The %s stands for the one ability a can(...) reference asks about, so its value is an ability '
                .'name; got %s.',
            $written,
            $got,
        );
    }

    /**
     * @param string $written The ability, binding or constant as words for the
     *   author, such as 'Ability [view]', 'Binding [:more]' or
     *   'Constant [payroll.editors]'.
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
