<?php

namespace Warrant\RuleSyntaxTree;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Compiles a {@see WarrantRuleSet} into SQL predicates.
 *
 * The unit of output is one nested {@see Builder} predicate per ability, ready
 * to be attached to a host query (directly for row filtering, or inside a
 * correlated subquery for per-row ability selection).
 *
 * Per ability A the predicate is:
 *
 *     ( OR of every `can` rule's if-expression that lists A or * )
 *       AND ( AND of NOT(every `cannot` rule's if-expression that lists A or *) )
 *
 * with these hard edges (deny-overrides):
 *   - an unconditional `cannot` (null if-expression) makes A impossible → 1 = 0;
 *   - an ability with no `can` rule is never granted → 1 = 0;
 *   - an unconditional `can` contributes an always-true term → 1 = 1.
 *
 * Condition leaves are wrapped as EXISTS subqueries so each is a strict boolean
 * (NULL → false) and negation via NOT EXISTS is exact — no three-valued-logic
 * surprises leak into authorization results.
 */
final class RuleSetCompiler
{
    public function __construct(private readonly ConditionResolver $conditions)
    {
    }

    /**
     * Build the predicate for a single ability as a nested query on $query.
     */
    public function compileAbility(
        Authenticatable $user,
        Builder $query,
        string $ability,
        WarrantRuleSet $ruleSet,
        ?string $targetSqlId = null,
        array $context = [],
    ): Builder {
        $predicate = $query->newQuery();

        /** @var list<IBooleanExpressionNode|null> $grants */
        $grants = [];
        /** @var list<IBooleanExpressionNode|null> $denies */
        $denies = [];

        foreach ($ruleSet->rules as $rule) {
            if ($this->listsAbility($rule->canAbilities, $ability)) {
                $grants[] = $rule->conditions;
            }

            if ($this->listsAbility($rule->cannotAbilities, $ability)) {
                $denies[] = $rule->conditions;
            }
        }

        // An unconditional `cannot` denies the ability outright, no matter what.
        foreach ($denies as $denyExpression) {
            if ($denyExpression === null) {
                return $predicate->whereRaw('1 = 0');
            }
        }

        // No `can` rule grants this ability.
        if ($grants === []) {
            return $predicate->whereRaw('1 = 0');
        }

        // Grant side: OR of every can-expression (null => always-true term).
        $predicate->where(function (Builder $grantGroup) use ($grants, $user, $targetSqlId, $context): void {
            foreach ($grants as $index => $grantExpression) {
                $boolean = $index === 0 ? 'and' : 'or';

                if ($grantExpression === null) {
                    $grantGroup->whereRaw('1 = 1', [], $boolean);

                    continue;
                }

                $this->applyExpression($grantGroup, $grantExpression, $user, $targetSqlId, $context, $boolean, false);
            }
        });

        // Deny side: AND NOT(expression) for each conditional `cannot`.
        foreach ($denies as $denyExpression) {
            $predicate->where(function (Builder $denyGroup) use ($denyExpression, $user, $targetSqlId, $context): void {
                $this->applyExpression($denyGroup, $denyExpression, $user, $targetSqlId, $context, 'and', true);
            });
        }

        return $predicate;
    }

    /**
     * Build a predicate that is true for the target row iff $condition matches —
     * a single condition tree in isolation, without the deny-overrides formula.
     *
     * Used by the singular-target denial diagnostic to test whether one `cannot`
     * rule's condition fired for the target. A null condition (an unconditional
     * `cannot`) always matches. Reuses the same leaf/EXISTS, targeted-vs-global,
     * and `@context` semantics as {@see compileAbility}, so a diagnostic re-run
     * agrees exactly with the live check.
     */
    public function matchesCondition(
        Authenticatable $user,
        Builder $query,
        ?IBooleanExpressionNode $condition,
        ?string $targetSqlId = null,
        array $context = [],
    ): Builder {
        $predicate = $query->newQuery();

        if ($condition === null) {
            return $predicate->whereRaw('1 = 1');
        }

        $this->applyExpression($predicate, $condition, $user, $targetSqlId, $context, 'and', false);

        return $predicate;
    }

    /**
     * @param array<int, string> $abilities
     */
    private function listsAbility(array $abilities, string $ability): bool
    {
        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }

    /**
     * Add $node's predicate to $parent under the given boolean connector,
     * negating via De Morgan so that negation always lands on the leaves (where
     * EXISTS / NOT EXISTS keeps it a strict boolean).
     */
    private function applyExpression(
        Builder $parent,
        IBooleanExpressionNode $node,
        Authenticatable $user,
        ?string $targetSqlId,
        array $context,
        string $boolean,
        bool $negate,
    ): void {
        if ($node instanceof NotNode) {
            $this->applyExpression($parent, $node->operand, $user, $targetSqlId, $context, $boolean, ! $negate);

            return;
        }

        if ($node instanceof AndNode || $node instanceof OrNode) {
            // NOT(a AND b) = NOT a OR NOT b ; NOT(a OR b) = NOT a AND NOT b.
            $childrenAreOr = $node instanceof OrNode;
            $innerSecondBoolean = ($childrenAreOr xor $negate) ? 'or' : 'and';

            $parent->where(function (Builder $group) use ($node, $user, $targetSqlId, $context, $negate, $innerSecondBoolean): void {
                $this->applyExpression($group, $node->leftSide, $user, $targetSqlId, $context, 'and', $negate);
                $this->applyExpression($group, $node->rightSide, $user, $targetSqlId, $context, $innerSecondBoolean, $negate);
            }, null, null, $boolean);

            return;
        }

        if ($node instanceof ConditionNode) {
            $this->applyCondition($parent, $node, $user, $targetSqlId, $context, $boolean, $negate);

            return;
        }

        if ($node instanceof BooleanNode) {
            $value = $negate ? ! $node->value : $node->value;
            $parent->whereRaw($value ? '1 = 1' : '1 = 0', [], $boolean);

            return;
        }

        throw new InvalidArgumentException(sprintf('Unsupported expression node [%s].', $node::class));
    }

    private function applyCondition(
        Builder $parent,
        ConditionNode $node,
        Authenticatable $user,
        ?string $targetSqlId,
        array $context,
        string $boolean,
        bool $negate,
    ): void {
        // A targeted condition cannot be evaluated without a row; force it false
        // (so `not <targeted>` becomes true) in a no-target compile.
        if ($targetSqlId === null && $this->conditions->conditionIsTargeted($node->conditionKey)) {
            $parent->whereRaw($negate ? '1 = 1' : '1 = 0', [], $boolean);

            return;
        }

        // Resolve any @context placeholder against the check-time context. An
        // absent key (only ever a non-required one — required keys are enforced
        // before compilation) resolves to null and is passed to the condition as
        // that argument's value, leaving the condition to decide what null means
        // (rather than the compiler forcing the whole leaf false). Conditions that
        // read a possibly-absent @context arg must therefore tolerate null.
        $parameters = [];
        foreach ($node->parameters as $parameter) {
            if ($parameter instanceof ContextRef) {
                $parameters[] = $context[$parameter->key] ?? null;

                continue;
            }

            $parameters[] = $parameter;
        }

        $existsQuery = $parent->newQuery();
        $existsQuery->selectRaw('1')->fromSub(
            fn (Builder $one) => $one->selectRaw('1'),
            'warrant_exists'
        );

        $result = $this->conditions->applyCondition(
            $node->conditionKey,
            $user,
            $existsQuery,
            $targetSqlId,
            $parameters,
            $context,
        );

        // A no-target condition may decide the outcome outright.
        if (is_bool($result)) {
            $value = $negate ? ! $result : $result;
            $parent->whereRaw($value ? '1 = 1' : '1 = 0', [], $boolean);

            return;
        }

        $parent->addWhereExistsQuery($existsQuery, $boolean, $negate);
    }
}
