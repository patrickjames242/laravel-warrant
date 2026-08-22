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
 * A condition leaf is wrapped as an EXISTS subquery only when it needs to be a
 * strict boolean: when it is negated (so NOT EXISTS is exact — NULL → false),
 * when it comes from a `cannot` rule (a deny is a subtraction; an unknown there
 * must fail safe), or when the condition emits more than a where-clause (e.g. a
 * join), which needs the subquery's isolation. A plain positive `can`
 * where-clause is applied inline instead: NULL already means "row excluded,"
 * which is the correct, safe outcome, and the correlated subquery buys nothing.
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

        $grantCtx = new CompilationContext($user, $targetSqlId, $context);

        // Grant side: OR of every can-expression (null => always-true term).
        $predicate->where(function (Builder $grantGroup) use ($grants, $grantCtx): void {
            foreach ($grants as $index => $grantExpression) {
                $boolean = $index === 0 ? 'and' : 'or';

                if ($grantExpression === null) {
                    $grantGroup->whereRaw('1 = 1', [], $boolean);

                    continue;
                }

                $this->applyExpression($grantGroup, $grantExpression, $grantCtx->withBoolean($boolean));
            }
        });

        // Deny side: AND NOT(expression) for each conditional `cannot`.
        $denyCtx = new CompilationContext($user, $targetSqlId, $context, negate: true, fromCannot: true);

        foreach ($denies as $denyExpression) {
            $predicate->where(function (Builder $denyGroup) use ($denyExpression, $denyCtx): void {
                $this->applyExpression($denyGroup, $denyExpression, $denyCtx);
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

        // This diagnoses a `cannot` rule's condition, so treat its leaves as
        // deny-side (fromCannot) — keeping them EXISTS-wrapped exactly as the
        // live check does, so a re-run agrees.
        $this->applyExpression(
            $predicate,
            $condition,
            new CompilationContext($user, $targetSqlId, $context, fromCannot: true),
        );

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
     * Add $node's predicate to $parent under the context's boolean connector,
     * negating via De Morgan so that negation always lands on the leaves (where
     * a negated leaf is EXISTS-wrapped to keep it a strict boolean). The context's
     * `fromCannot` marks a leaf as originating from a `cannot` rule; it rides down
     * unchanged (De Morgan flips only `negate`) and forces EXISTS-wrapping at the
     * leaf even when a double negation leaves the leaf positive.
     */
    private function applyExpression(Builder $parent, IBooleanExpressionNode $node, CompilationContext $ctx): void
    {
        if ($node instanceof NotNode) {
            $this->applyExpression($parent, $node->operand, $ctx->negated());

            return;
        }

        if ($node instanceof AndNode || $node instanceof OrNode) {
            // NOT(a AND b) = NOT a OR NOT b ; NOT(a OR b) = NOT a AND NOT b.
            $childrenAreOr = $node instanceof OrNode;
            $innerSecondBoolean = ($childrenAreOr xor $ctx->negate) ? 'or' : 'and';

            $parent->where(function (Builder $group) use ($node, $ctx, $innerSecondBoolean): void {
                $this->applyExpression($group, $node->leftSide, $ctx->withBoolean('and'));
                $this->applyExpression($group, $node->rightSide, $ctx->withBoolean($innerSecondBoolean));
            }, null, null, $ctx->boolean);

            return;
        }

        if ($node instanceof ConditionNode) {
            $this->applyCondition($parent, $node, $ctx);

            return;
        }

        if ($node instanceof BooleanNode) {
            $value = $ctx->negate ? ! $node->value : $node->value;
            $parent->whereRaw($value ? '1 = 1' : '1 = 0', [], $ctx->boolean);

            return;
        }

        throw new InvalidArgumentException(sprintf('Unsupported expression node [%s].', $node::class));
    }

    private function applyCondition(Builder $parent, ConditionNode $node, CompilationContext $ctx): void
    {
        // A targeted condition cannot be evaluated without a row; force it false
        // (so `not <targeted>` becomes true) in a no-target compile.
        if ($ctx->targetSqlId === null && $this->conditions->conditionIsTargeted($node->conditionKey)) {
            $parent->whereRaw($ctx->negate ? '1 = 1' : '1 = 0', [], $ctx->boolean);

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
                $parameters[] = $ctx->checkContext[$parameter->key] ?? null;

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
            $ctx->user,
            $existsQuery,
            $ctx->targetSqlId,
            $parameters,
            $ctx->checkContext,
        );

        // A no-target condition may decide the outcome outright.
        if (is_bool($result)) {
            $value = $ctx->negate ? ! $result : $result;
            $parent->whereRaw($value ? '1 = 1' : '1 = 0', [], $ctx->boolean);

            return;
        }

        // A plain positive `can` where-clause is applied inline (correlated,
        // no subquery): NULL already means "row excluded," the safe outcome.
        // The EXISTS wrapper is kept when a leaf is negated, comes from a
        // `cannot`, or the condition emitted more than a plain, non-empty
        // where-clause:
        //   - a join/group/having/aggregate needs the subquery's isolation —
        //     addNestedWhereQuery merges only `wheres`, silently dropping them;
        //   - a condition that added no where at all means "match every row",
        //     which EXISTS renders as an always-true term — inlining it would
        //     contribute nothing and wrongly vanish from an OR.
        $isPlainNonEmptyWhereClause = ! empty($existsQuery->wheres)
            && empty($existsQuery->joins)
            && empty($existsQuery->groups)
            && empty($existsQuery->havings)
            && $existsQuery->aggregate === null;

        if (! $ctx->negate && ! $ctx->fromCannot && $isPlainNonEmptyWhereClause) {
            $parent->addNestedWhereQuery($existsQuery, $ctx->boolean);

            return;
        }

        $parent->addWhereExistsQuery($existsQuery, $ctx->boolean, $ctx->negate);
    }
}
