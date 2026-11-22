<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * The mistakes abilities can be written, bound or named by a constant with.
 *
 * An ability list is judged two ways. As written, which the validator reports:
 * an ability, binding or constant written twice, or `*` written beside anything
 * else. That judgement never reads a value: `view, :more` and `view, editors`
 * hold no mistake whatever `:more` and `editors` name, while `:more, :more`
 * writes one binding twice. A rule's `can` clauses are one list, and so are its
 * `cannot` clauses, since all of them grant or deny for the one condition.
 *
 * And by the value of each binding or constant, which in a list is an ability
 * name, `*`, or a non-empty list of them, and as the ability a `can(...)`
 * reference asks about is one ability name. The validator reports a value that
 * is neither, and the expander rejects one when it puts the value in place, so
 * both judge values here.
 */
final class AbilityMistakes
{
    /**
     * The mistakes in the lists $entry writes itself: a rule's, an ability
     * block header's, or an `@include`'s `for` list. The entries in a block's
     * body are not looked into.
     *
     * @return list<array{0: DiagnosticCode, 1: string, 2: SyntaxLocation}>
     */
    public static function inWrittenLists(IRuleEntryNode $entry): array
    {
        return match (true) {
            $entry instanceof RuleNode => [
                ...self::inWrittenList(self::abilitiesOf($entry->canClauses), 'this rule grants'),
                ...self::inWrittenList(self::abilitiesOf($entry->cannotClauses), 'this rule denies'),
            ],
            $entry instanceof AbilityBlockNode => self::inWrittenList($entry->abilities, 'this ability block names'),
            $entry instanceof IncludeInvocationNode => self::inWrittenList($entry->abilities, 'this @include names'),
            default => [],
        };
    }

    /**
     * @param list<NameNode|BindingNode|ConstantRef> $abilities
     * @param string $among The abilities the list is, as words for the author.
     * @return list<array{0: DiagnosticCode, 1: string, 2: SyntaxLocation}>
     */
    private static function inWrittenList(array $abilities, string $among): array
    {
        $mistakes = [];
        $seen = [];
        $star = null;

        foreach ($abilities as $ability) {
            $written = match (true) {
                $ability instanceof BindingNode => 'Binding [:'.$ability->name.']',
                $ability instanceof ConstantRef => 'Constant ['.$ability->written().']',
                default => 'Ability ['.$ability->name.']',
            };

            if (isset($seen[$written])) {
                $mistakes[] = [DiagnosticCode::DuplicateAbility, LanguageRuleMessages::abilityWrittenTwice($written, $among), $ability->location];
            }

            $seen[$written] = true;

            if ($ability instanceof NameNode && $ability->name === '*') {
                $star ??= $ability;
            }
        }

        if ($star !== null && count($seen) > 1) {
            $mistakes[] = [DiagnosticCode::StarBesideAbilities, LanguageRuleMessages::starBesideAbilities($among), $star->location];
        }

        return $mistakes;
    }

    /**
     * @param list<\Warrant\DSL\Parsing\ASTNodes\CanClauseNode|\Warrant\DSL\Parsing\ASTNodes\CannotClauseNode> $clauses
     * @return list<NameNode|BindingNode|ConstantRef>
     */
    private static function abilitiesOf(array $clauses): array
    {
        return array_merge([], ...array_map(static fn ($clause): array => $clause->abilities, $clauses));
    }

    /**
     * The abilities $value names: an ability name, `*`, or each name in a list.
     * Only a value {@see inListValue()} finds nothing wrong with names any.
     *
     * @return list<string>
     */
    public static function namesIn(mixed $value): array
    {
        return is_array($value) ? $value : [$value];
    }

    /**
     * What is wrong with $value as the abilities $written names in an ability
     * list, or null when it is an ability name, `*`, or a non-empty list of them.
     *
     * @param BindingNode|ConstantRef $written The binding or constant whose
     *   value it is.
     */
    public static function inListValue(BindingNode|ConstantRef $written, mixed $value): ?string
    {
        $names = is_array($value) ? $value : [$value];
        $notNames = array_filter($names, static fn (mixed $name): bool => ! is_string($name));

        $got = match (true) {
            $names === [] => 'an empty list',
            ! array_is_list($names) => 'an array with keys',
            $notNames === [] => null,
            is_array($value) => 'a list holding '.get_debug_type(reset($notNames)),
            default => get_debug_type($value),
        };

        return $got === null ? null : LanguageRuleMessages::abilityListValue(self::describe($written), $got);
    }

    /**
     * What is wrong with $value as the one ability a `can(...)` reference asks
     * about through $written, or null when it is an ability name.
     *
     * @param BindingNode|ConstantRef $written The binding or constant whose
     *   value it is.
     */
    public static function inOneAbilityValue(BindingNode|ConstantRef $written, mixed $value): ?string
    {
        return is_string($value) ? null : LanguageRuleMessages::oneAbilityValue(self::describe($written), get_debug_type($value));
    }

    /**
     * $written as words for the author: `binding [:more]` or `constant [editors]`.
     */
    private static function describe(BindingNode|ConstantRef $written): string
    {
        return $written instanceof BindingNode ? 'binding [:'.$written->name.']' : 'constant ['.$written->written().']';
    }
}
