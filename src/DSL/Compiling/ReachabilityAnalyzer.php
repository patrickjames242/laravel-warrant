<?php

namespace Warrant\DSL\Compiling;

use Closure;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Expanding\ExpandedRuleSet;
use Warrant\DSL\Expanding\UnknownNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\Reachability;
use Warrant\Support\Set;

/**
 * Answers "could a user ever hold ability X?" from an {@see ExpandedRuleSet},
 * without a row, a context or a query.
 *
 * It folds an ability exactly as {@see RuleSetCompiler::abilityNode()} does —
 * the grants ORed together, then ANDed with the negation of every deny — but over
 * the {@see TruthSet} of values each condition could still take rather than the
 * one value it takes for a row. The ability is held where the fold is true, so:
 *
 *  - NEVER  when the outcome can never be true (no grant, an unconditional deny,
 *           or every grant hinging on something that is never true);
 *  - ALWAYS when it can only be true;
 *  - MAYBE  otherwise.
 *
 * What each part of a condition may answer:
 *
 *  - a row or global condition: anything — it is never evaluated here;
 *  - a constant, or a derived condition that answered one: that value, and a
 *    derived condition that answered null, unknown; any other derived condition
 *    is whatever its expansion may answer;
 *  - `can(x)` and unbound `can(x for B)`: whatever ability x may come out as, in
 *    the rule set this user is given for that schema, analyzed the same way;
 *  - `check(p for B)`: whatever p may answer, its own `can(...)`s about B;
 *  - a row-bound `can(... for B(...))` or `check(... for B(...))`: as above, but
 *    also false or unknown — the row may not exist, or its key may not resolve —
 *    so one can rule a grant out, never in.
 *
 * An ability reached again while it is still being analyzed — a cycle, which the
 * compiler rejects — and a schema whose rule set cannot be had answer anything,
 * which keeps the result safe rather than sharp.
 *
 * One analyzer serves one user: outcomes are memoized by schema and ability.
 */
final class ReachabilityAnalyzer
{
    /** @var array<string, ?ExpandedRuleSet> */
    private array $ruleSets = [];

    /** @var array<string, TruthSet> */
    private array $outcomes = [];

    /**
     * The calls being analyzed further up, keyed as {@see callKey()}: one reached
     * again before it finishes is a cycle.
     *
     * @var Set<string>
     */
    private Set $inProgress;

    /**
     * @param (Closure(string): ?ExpandedRuleSet)|null $resolveRuleSet The rule set
     *   the same user is given for another schema, by key, or null when there is
     *   none to be had. Without one, every reference to another schema answers
     *   anything.
     */
    public function __construct(private readonly ?Closure $resolveRuleSet = null)
    {
        $this->inProgress = new Set;
    }

    public function analyze(ExpandedRuleSet $ruleSet, string $ability): Reachability
    {
        $this->ruleSets[$ruleSet->schemaKey] = $ruleSet;

        return $this->abilityOutcome($ruleSet, $ability)->toReachability();
    }

    private function abilityOutcome(ExpandedRuleSet $ruleSet, string $ability): TruthSet
    {
        $call = self::callKey($ruleSet->schemaKey, $ability);

        if (isset($this->outcomes[$call])) {
            return $this->outcomes[$call];
        }

        if ($this->inProgress->has($call)) {
            return TruthSet::any();
        }

        $this->inProgress->add($call);

        try {
            $outcome = $this->fold($ruleSet, $ability);
        } finally {
            $this->inProgress->remove($call);
        }

        return $this->outcomes[$call] = $outcome;
    }

    /**
     * The grants ORed together, then ANDed with the negation of every deny — the
     * compiler's own fold, over the values each condition could take.
     */
    private function fold(ExpandedRuleSet $ruleSet, string $ability): TruthSet
    {
        $grant = TruthSet::of(false);
        $denies = [];

        foreach ($ruleSet->rules as $rule) {
            $grants = $this->listsAbility($rule->canAbilities(), $ability);
            $denied = $this->listsAbility($rule->cannotAbilities(), $ability);

            if (! $grants && ! $denied) {
                continue;
            }

            $condition = $rule->conditions === null
                ? TruthSet::of(true)
                : $this->expression($rule->conditions, $ruleSet->schemaKey);

            if ($grants) {
                $grant = $grant->or($condition);
            }

            if ($denied) {
                $denies[] = $condition;
            }
        }

        $outcome = $grant;

        foreach ($denies as $deny) {
            $outcome = $outcome->and($deny->not());
        }

        return $outcome;
    }

    private function expression(IBooleanExpressionNode $node, string $schemaKey): TruthSet
    {
        if ($node instanceof AndNode) {
            return $this->expression($node->leftSide, $schemaKey)
                ->and($this->expression($node->rightSide, $schemaKey));
        }

        if ($node instanceof OrNode) {
            return $this->expression($node->leftSide, $schemaKey)
                ->or($this->expression($node->rightSide, $schemaKey));
        }

        if ($node instanceof NotNode) {
            return $this->expression($node->operand, $schemaKey)->not();
        }

        if ($node instanceof BooleanNode) {
            return TruthSet::of($node->value);
        }

        if ($node instanceof UnknownNode) {
            return TruthSet::of(null);
        }

        if ($node instanceof DerivedConditionNode) {
            return $this->expression($node->body, $schemaKey);
        }

        if ($node instanceof CrossSchemaCanNode) {
            $ruleSet = $this->ruleSetFor($node->schemaKey ?? $schemaKey);

            if ($ruleSet === null) {
                return TruthSet::any();
            }

            $outcome = $this->abilityOutcome($ruleSet, $node->ability);

            return $node->schemaKey !== null && $node->isRowBound ? self::overARow($outcome) : $outcome;
        }

        if ($node instanceof CrossSchemaConditionNode) {
            $outcome = $this->expression($node->predicate, $node->schemaKey);

            return $node->isRowBound ? self::overARow($outcome) : $outcome;
        }

        /* A row or global condition, which is never evaluated here — and anything
           else, which nothing here knows how to read. */
        return TruthSet::any();
    }

    /**
     * What a question about one row of another schema may answer, given what it
     * may answer about a row that is there.
     *
     * The row may not be there, which is false, or its key may not resolve, which
     * is unknown. Either is possible whatever the rules say, so a row-bound
     * reference can rule a grant out, but never guarantee one.
     */
    private static function overARow(TruthSet $outcome): TruthSet
    {
        return $outcome->canBeTrue() ? TruthSet::any() : TruthSet::of(false)->union(TruthSet::of(null));
    }

    private function ruleSetFor(string $schemaKey): ?ExpandedRuleSet
    {
        if (! array_key_exists($schemaKey, $this->ruleSets)) {
            $this->ruleSets[$schemaKey] = $this->resolveRuleSet === null
                ? null
                : ($this->resolveRuleSet)($schemaKey);
        }

        return $this->ruleSets[$schemaKey];
    }

    private static function callKey(string $schemaKey, string $ability): string
    {
        return $schemaKey."\0".$ability;
    }

    /**
     * @param array<int, string> $abilities
     */
    private function listsAbility(array $abilities, string $ability): bool
    {
        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }
}
