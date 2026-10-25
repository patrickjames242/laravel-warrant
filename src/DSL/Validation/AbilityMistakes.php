<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\RuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * The mistakes abilities can be written or bound with.
 *
 * An ability list is judged two ways. As written, which the validator reports:
 * an ability or a binding written twice, or `*` written beside anything else.
 * That judgement never reads a binding's value: `view, :more` holds no mistake
 * whatever `:more` names, while `:more, :more` writes one binding twice. A
 * rule's `can` clauses are one list, and so are its `cannot` clauses, since all
 * of them grant or deny for the one condition.
 *
 * And by each binding's value, which in a list is an ability name, `*`, or a
 * non-empty list of them, and as the ability a `can(...)` reference asks about
 * is one ability name. The validator reports a value that is neither when
 * binding values are checked, and the expander rejects one when it puts the
 * value in the binding's place, so both judge values here.
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
    public static function inWrittenLists(RuleEntryNode $entry): array
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
     * @param list<NameNode|BindingNode> $abilities
     * @param string $among The abilities the list is, as words for the author.
     * @return list<array{0: DiagnosticCode, 1: string, 2: SyntaxLocation}>
     */
    private static function inWrittenList(array $abilities, string $among): array
    {
        $mistakes = [];
        $seen = [];
        $star = null;

        foreach ($abilities as $ability) {
            $written = $ability instanceof BindingNode ? 'Binding [:'.$ability->name.']' : 'Ability ['.$ability->name.']';

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
     * @return list<NameNode|BindingNode>
     */
    private static function abilitiesOf(array $clauses): array
    {
        return array_merge([], ...array_map(static fn ($clause): array => $clause->abilities, $clauses));
    }

    /**
     * The abilities $binding's value names: an ability name, `*`, or each name
     * in a list. Only a value {@see inBoundList()} finds nothing wrong with
     * names any.
     *
     * @return list<string>
     */
    public static function boundNames(BindingNode $binding): array
    {
        $value = $binding->value();

        return is_array($value) ? $value : [$value];
    }

    /**
     * What is wrong with $binding's value as abilities in an ability list, or
     * null when it is an ability name, `*`, or a non-empty list of them.
     */
    public static function inBoundList(BindingNode $binding): ?string
    {
        $value = $binding->value();
        $names = is_array($value) ? $value : [$value];
        $notNames = array_filter($names, static fn (mixed $name): bool => ! is_string($name));

        $got = match (true) {
            $names === [] => 'an empty list',
            ! array_is_list($names) => 'an array with keys',
            $notNames === [] => null,
            is_array($value) => 'a list holding '.get_debug_type(reset($notNames)),
            default => get_debug_type($value),
        };

        return $got === null ? null : LanguageRuleMessages::abilityListBindingValue($binding->name, $got);
    }

    /**
     * What is wrong with $binding's value as the one ability a `can(...)`
     * reference asks about, or null when it is an ability name.
     */
    public static function inBoundAbility(BindingNode $binding): ?string
    {
        $value = $binding->value();

        return is_string($value) ? null : LanguageRuleMessages::oneAbilityBindingValue($binding->name, get_debug_type($value));
    }
}
