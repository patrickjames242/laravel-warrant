<?php

namespace Warrant\DSL\Compiling\Concerns;

use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\CallStack\Call;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Compiling\Units\AbilityUnit;
use Warrant\DSL\Compiling\Units\ConditionUnit;
use Warrant\DSL\Compiling\Units\GateUnit;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedRule;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\Facades\Warrant;

/**
 * How {@see RuleSetCompiler} turns each kind of unit into a predicate: a gate
 * combines its abilities under the match mode, an ability applies its rules under
 * deny-overrides, and a condition compiles a bare expression with no rules.
 *
 * @internal
 */
trait CompilesUnits
{
    /**
     * The tree for a whole gate — the requested abilities combined under the
     * gate's match mode.
     *
     * Each ability is compiled independently by {@see abilityNode} and the
     * results are joined here: `ALL` ANDs them (every ability must hold for a
     * row), `ANY` ORs them (any one is enough). This is the only method that
     * consults {@see AbilityMatchMode}. Joining trees rather than finished
     * predicates lets a constant cross the ability boundary — an `ANY` gate over
     * an unconditionally granted ability is just `true`, with the other
     * abilities never appearing in the SQL at all.
     *
     * An empty gate (no abilities) folds to `true` — a match-all — but callers
     * short-circuit that case upstream (see the guard's `filterQuery`).
     *
     * All abilities in one gate share the same incoming cross-schema path: they
     * are siblings, not nested references.
     */
    private function gateNode(CompilationContext $ctx, GateUnit $unit): CompiledWhereClauseNode
    {
        $gateNode = new CompiledWhereClauseNode;
        $requireAll = $unit->gate->matchMode === AbilityMatchMode::ALL;

        foreach ($unit->gate->abilities as $ability) {
            $abilityNode = $this->abilityNode($ctx, new AbilityUnit($ability, $unit->ruleSet));

            $requireAll ? $gateNode->addAnd($abilityNode) : $gateNode->addOr($abilityNode);
        }

        return $gateNode;
    }

    /**
     * The tree for one ability — the unit a gate ORs or ANDs, and the unit a
     * cross-schema `can(...)` splices in, so a constant folds across both
     * boundaries instead of stopping at a `1 = 1`.
     */
    private function abilityNode(CompilationContext $ctx, AbilityUnit $unit): CompiledWhereClauseNode
    {
        $ability = $unit->ability;
        $ruleSet = $unit->ruleSet;

        /* An ability nobody declares is a name that resolves to nothing, which is
           a different thing from an ability no rule happens to grant — and the two
           are indistinguishable further down, where both come out as "no grants".
           Asking here keeps a misspelled name from being answered rather than
           reported. */
        $this->assertAbilityDeclared($ability);

        /* Entering the ability is where a cycle back to one already in progress is
           caught, and it puts the ability on the stack every leaf below reads. */
        $ctx = $ctx->entering(Call::ability($this->schema::class, $ability));

        $abilityNode = new CompiledWhereClauseNode;

        /** @var list<IBooleanExpressionNode|null> $grants */
        $grants = [];
        /** @var list<IBooleanExpressionNode|null> $denies */
        $denies = [];

        foreach ($ruleSet->rules as $rule) {
            $this->assertRuleAbilitiesDeclared($rule);

            if (in_array($ability, $rule->grantedAbilities(), true)) {
                $grants[] = $rule->node->conditions;
            }

            if ($rule->denies($ability)) {
                $denies[] = $rule->node->conditions;
            }
        }

        // An unconditional `cannot` denies the ability outright, no matter what.
        foreach ($denies as $denyExpression) {
            if ($denyExpression === null) {
                return $abilityNode->addAnd(false);
            }
        }

        // No `can` rule grants this ability.
        if ($grants === []) {
            return $abilityNode->addAnd(false);
        }

        $grantCtx = $ctx;

        // Grant side: OR of every can-expression (null => always-true term).
        $grantGroup = new CompiledWhereClauseNode;

        foreach ($grants as $grantExpression) {
            $grantGroup->addOr(
                $grantExpression === null ? true : $this->expression($grantExpression, $this->writtenIn($grantCtx, $grantExpression)),
            );
        }

        $abilityNode->addAnd($grantGroup);

        /* Deny side: AND NOT(expression) for each conditional `cannot`. A unit is
           always entered unnegated, so toggling here is setting. */
        $denyCtx = $ctx->negated();

        foreach ($denies as $denyExpression) {
            $abilityNode->addAnd($this->expression($denyExpression, $this->writtenIn($denyCtx, $denyExpression)));
        }

        return $abilityNode;
    }

    /**
     * The tree for a standalone condition — an expression compiled in isolation,
     * without the deny-overrides formula, true for the target row iff it matches.
     *
     * Two callers: the singular-target denial diagnostic (does one `cannot` rule's
     * condition fire for the target?), and {@see crossSchemaCheckLeaf}, which uses
     * it to compile a `check(...)` predicate against the *target* schema's resolver.
     * A null condition (an unconditional `cannot`) always matches. Reuses the same
     * inline leaf, targeted-vs-global, and `@context` semantics as
     * {@see abilityNode}, so a re-run agrees exactly with the live check.
     */
    private function conditionNode(CompilationContext $ctx, ConditionUnit $unit): CompiledWhereClauseNode
    {
        $conditionNode = new CompiledWhereClauseNode;

        if ($unit->condition === null) {
            return $conditionNode->addAnd(true);
        }

        /* A check(...) predicate arrives already told which schema its names
           are written for; any other condition is settled from its text. */
        return $conditionNode->addAnd(
            $this->expression($unit->condition, $ctx->writtenIn === null ? $this->writtenIn($ctx, $unit->condition) : $ctx),
        );
    }

    /**
     * Every ability a rule lists must be one the schema declares.
     *
     * The mirror of {@see \Warrant\DSL\Validation\ValidatesRuleEntries}'s
     * check over rule text, made here because a rule reaches a compile by paths no
     * validation ever ran over: one built with the fluent builder, one a template
     * expanded into rules, a set assembled programmatically.
     *
     * A name nothing declares is otherwise silently inert rather than wrong. It
     * matches no ability being compiled, so the rule never fires, and a misspelt
     * `they can veiw` reads as a considered decision to grant nothing.
     */
    private function assertRuleAbilitiesDeclared(ExpandedRule $rule): void
    {
        foreach ([...$rule->grantedAbilities(), ...$rule->deniedAbilities()] as $ability) {
            if ($this->schema->ability($ability) === null) {
                throw new InvalidArgumentException(
                    sprintf('Ability [%s] is not declared by the schema.', $ability)
                );
            }
        }
    }

    /**
     * Assert the schema being compiled declares $ability.
     *
     * The compiler's own guard against a name that resolves to nothing, held
     * separately from validation because nothing makes validation run: a rule set
     * can reach a compile without the guard that validates it. Both paths reach
     * here.
     */
    private function assertAbilityDeclared(string $ability): void
    {
        if ($this->schema->ability($ability) !== null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Ability [%s] is not declared by schema [%s].',
            $ability,
            $this->schemaKey,
        ));
    }
}
