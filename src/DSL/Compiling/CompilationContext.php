<?php

namespace Warrant\DSL\Compiling;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Compiling\Units\AbilityUnit;
use Warrant\DSL\Compiling\Units\CompilationUnit;
use Warrant\DSL\Compiling\Units\ConditionUnit;
use Warrant\DSL\Compiling\Units\GateUnit;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\Rules\WarrantRuleSet;
use Warrant\WarrantGate;

/**
 * Everything one call to {@see RuleSetCompiler::compile()} needs, and everything
 * the walk derives on the way down: the {@see CompilationUnit} being compiled,
 * the check-time facts that are the same for all three kinds of unit, the
 * {@see CallStack} of layers already descended through, and whether the subtree
 * being built sits under a `not`.
 *
 * Build one with a named constructor and refine it with the withers, so that the
 * things a compile always needs are positional and the things it usually does
 * not are opt-in. The constructor is public for the compiler's own cross-schema
 * descent, which already holds the unit it wants to compile:
 *
 *     CompilationContext::ability($queries, $user, 'view', $ruleSet)
 *         ->forTargetRow($document)
 *         ->withCheckContext($context)
 *
 * ## Targeted vs. no-target
 *
 * A predicate is compiled either with the schema's row in scope at the place it
 * will eventually be spliced, or without. That distinction is what
 * {@see forTargetRow()} and {@see withoutTarget()} express, and it is genuinely
 * the caller's to make: the compiler produces a *detached* predicate and has no
 * way to see where it lands. `selectAbilitiesInQuery` is the proof — it compiles
 * against the entity query but splices the result into a correlated subquery
 * that has no `from` of its own, and the row is in scope only because of the
 * query wrapped around it.
 *
 * The consequence of getting it wrong is not subtle, which is why it is stated
 * rather than guessed: with no row in scope, a row condition cannot be evaluated
 * at all, so the compiler folds it to `false` instead of emitting a column
 * reference to a table that is not in the query.
 *
 * The row's SQL *name* is a separate question from whether a row is in scope, and
 * it is not the caller's either: {@see aliases} answers it, and
 * {@see RuleSetCompiler::compile()} fills it in from the schema and the host query
 * when a caller leaves it null — which every caller does.
 *
 * ## What the walk adds
 *
 * Three things, and all of them derived rather than supplied. {@see negate} flips
 * at each `not` so that negation lands on the leaves rather than wrapping groups;
 * {@see callStack} grows by one {@see Call} at each ability, cross-schema
 * `check(...)`, and expanding condition — which is where a cycle is caught and
 * where the depth budget is spent; and {@see aliases} is rebound at each
 * cross-schema hop, so a `@column` reference means the frame it was reached in
 * rather than a fixed table. See {@see AliasScope}.
 *
 * What is *not* here is the connector a predicate attaches under: the walk builds
 * a {@see \Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode} whose
 * operands each carry their own connector, so position never has to be threaded
 * through. Nor is the host query — {@see queries} is a {@see QueryFactory}, which
 * is all the walk ever wanted from it: a source of fresh builders plus the
 * grammar to quote identifiers with. Carrying it here is what lets every step
 * take one argument instead of a context and a builder side by side.
 *
 * Immutable: every wither returns a modified copy, so a step can derive a child
 * context without disturbing its own, and a branch's calls never leak into a
 * sibling's.
 */
final readonly class CompilationContext
{
    /**
     * The layers this compile descended through to get here — ability hops,
     * cross-schema `check(...)` dispatches, and conditions that expanded into
     * further expressions, in call order. See {@see CallStack}.
     */
    public CallStack $callStack;

    /**
     * @param bool $targeted Whether the schema's row is in scope where this
     *   predicate will be spliced. See the class docblock.
     *   {@see RuleSetCompiler::compile()} narrows a `true` to `false` for a schema
     *   with no model, so what the walk reads is the caller's request already
     *   reconciled with what the schema can support.
     * @param Model|null $targetModel The loaded target row, when the caller had a
     *   hydrated one. Reaches a row condition as `$c->model`, letting it answer in
     *   PHP rather than in SQL; null whenever the compile covers more than one row.
     *   Only ever set alongside `$targeted`.
     * @param array<string, mixed> $checkContext The effective check-time context.
     * @param bool $negate Whether this subtree sits under an odd number of `not`s.
     *   Internal: derived by the walk, never set by a caller.
     * @param CallStack|null $callStack The layers already descended through;
     *   defaults to an empty stack. Threaded by the compiler's own recursion.
     * @param AliasScope|null $aliases The names a `@column` reference may use here
     *   and the SQL qualifier each stands for. Null means "not decided yet", which
     *   {@see RuleSetCompiler::compile()} settles on the way in; the compiler's own
     *   cross-schema descent passes the derived scope, since only it knows the
     *   frame it is building.
     */
    public function __construct(
        public CompilationUnit $unit,
        public QueryFactory $queries,
        public Authenticatable $user,
        public bool $targeted = false,
        public ?Model $targetModel = null,
        public array $checkContext = [],
        public bool $negate = false,
        ?CallStack $callStack = null,
        public ?AliasScope $aliases = null,
    ) {
        $this->callStack = $callStack ?? CallStack::root();
    }

    /**
     * Compile a whole gate — the requested abilities combined under their match
     * mode into a single predicate.
     */
    public static function gate(
        QueryFactory $queries,
        Authenticatable $user,
        WarrantGate $gate,
        WarrantRuleSet $ruleSet,
    ): self {
        return new self(new GateUnit($gate, $ruleSet), $queries, $user);
    }

    /**
     * Compile a single ability under the deny-overrides formula.
     */
    public static function ability(
        QueryFactory $queries,
        Authenticatable $user,
        string $ability,
        WarrantRuleSet $ruleSet,
    ): self {
        return new self(new AbilityUnit($ability, $ruleSet), $queries, $user);
    }

    /**
     * Compile a bare expression tree in isolation, with no rules applied.
     */
    public static function condition(
        QueryFactory $queries,
        Authenticatable $user,
        ?IBooleanExpressionNode $condition,
    ): self {
        return new self(new ConditionUnit($condition), $queries, $user);
    }

    /**
     * Compile with the schema's row in scope, optionally naming the loaded row.
     *
     * Pass the model whenever the caller holds one and it is trustworthy — a row
     * condition handed the very row it is judging can answer in PHP, and the
     * result folds away without reaching the database.
     */
    public function forTargetRow(?Model $targetModel = null): self
    {
        return $this->with(targeted: true, targetModel: $targetModel);
    }

    /**
     * Compile with no row in scope, so row conditions fold to `false`. This is
     * the default; the method exists to let a caller say so out loud.
     */
    public function withoutTarget(): self
    {
        return $this->with(targeted: false, targetModel: null);
    }

    /**
     * @param array<string, mixed> $checkContext
     */
    public function withCheckContext(array $checkContext): self
    {
        return $this->with(checkContext: $checkContext);
    }

    /**
     * Name the rows in scope here. Set once, by {@see RuleSetCompiler::compile()},
     * for a context a caller built without one.
     */
    public function withAliases(AliasScope $aliases): self
    {
        return $this->with(aliases: $aliases);
    }

    /**
     * Derive a copy with the negation flag toggled (crossing a `not`).
     */
    public function negated(): self
    {
        return $this->with(negate: ! $this->negate);
    }

    /**
     * Derive a copy one layer deeper, for a step that descends into something the
     * author's rules do not show — a condition expanding into an expression, say,
     * or an ability resolved through `can(...)`.
     *
     * Negation carries over: the deeper layer is compiled under whatever polarity
     * the leaf that entered it was already under.
     *
     * @throws CrossSchemaCycleException|CompileDepthException Via {@see CallStack::enter()}.
     */
    public function entering(Call $call): self
    {
        return $this->with(callStack: $this->callStack->enter($call));
    }

    /**
     * @param array<string, mixed>|null $checkContext
     */
    private function with(
        ?CompilationUnit $unit = null,
        ?bool $targeted = null,
        ?Model $targetModel = null,
        ?array $checkContext = null,
        ?bool $negate = null,
        ?CallStack $callStack = null,
        ?AliasScope $aliases = null,
    ): self {
        return new self(
            unit: $unit ?? $this->unit,
            queries: $this->queries,
            user: $this->user,
            targeted: $targeted ?? $this->targeted,
            /* Not `??`: clearing the model is meaningful, and every caller that
               changes the target passes both halves together. */
            targetModel: $targeted === null ? $this->targetModel : $targetModel,
            checkContext: $checkContext ?? $this->checkContext,
            negate: $negate ?? $this->negate,
            callStack: $callStack ?? $this->callStack,
            aliases: $aliases ?? $this->aliases,
        );
    }
}
