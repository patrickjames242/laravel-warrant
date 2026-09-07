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
 * Everything one call to {@see RuleSetCompiler::compile()} needs: the
 * {@see CompilationUnit} being compiled, plus the check-time facts that are the
 * same for all three kinds of unit.
 *
 * Build one with a named constructor and refine it with the withers, so that the
 * things a compile always needs are positional and the things it usually does
 * not are opt-in:
 *
 *     CompilationInput::ability($queries, $user, 'view', $ruleSet)
 *         ->forTargetRow($document)
 *         ->withContext($context)
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
 * The row's SQL identity is *not* part of this. It is derived from the schema's
 * own model by {@see RuleSetCompiler}, which is where
 * {@see \Warrant\Schema\Concerns\ResolvesConditions} independently derives it
 * too — so a caller has nothing useful to say about it and is no longer asked.
 *
 * Immutable: every wither returns a modified copy.
 */
final readonly class CompilationInput
{
    /**
     * @param bool $targeted Whether the schema's row is in scope where this
     *   predicate will be spliced. See the class docblock.
     * @param Model|null $targetModel The loaded target row, when the caller had a
     *   hydrated one. Reaches a row condition as `$c->model`, letting it answer in
     *   PHP rather than in SQL; null whenever the compile covers more than one row.
     *   Only ever set alongside `$targeted`.
     * @param array<string, mixed> $context The effective check-time context.
     * @param list<string> $visited The cross-schema `(schema, ability)` frames
     *   already on this compile path. Internal: threaded by the compiler's own
     *   recursion, never set by a caller.
     */
    private function __construct(
        public CompilationUnit $unit,
        public QueryFactory $queries,
        public Authenticatable $user,
        public bool $targeted = false,
        public ?Model $targetModel = null,
        public array $context = [],
        public array $visited = [],
    ) {
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
     * The same compile, one frame deeper on the cross-schema path.
     *
     * @internal Threaded by {@see RuleSetCompiler}'s own recursion as it enters an
     *   ability; never called by a caller.
     *
     * @param list<string> $visited
     */
    public function withVisited(array $visited): self
    {
        return $this->with(visited: $visited);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return $this->with(context: $context);
    }

    /**
     * The input for a compile of another schema, reached through a cross-schema
     * `can(...)` or `check(...)`.
     *
     * B sees only the explicit `with` map as its context — never A's ambient bag —
     * and inherits A's compile path so a cycle back to a frame already on it is
     * detected. It starts untargeted; the row-bound branches chain
     * {@see forTargetRow()} on top, since A's row is never B's row though the row
     * *selector* may itself have been B's.
     *
     * @internal Used by {@see RuleSetCompiler} to descend across a schema
     *   boundary; not part of the caller-facing surface.
     *
     * @param array<string, mixed> $context
     * @param list<string> $visited
     */
    public static function descending(
        QueryFactory $queries,
        Authenticatable $user,
        CompilationUnit $unit,
        array $context,
        array $visited,
    ): self {
        return new self(
            unit: $unit,
            queries: $queries,
            user: $user,
            context: $context,
            visited: $visited,
        );
    }

    /**
     * @param array<string, mixed>|null $context
     * @param list<string>|null $visited
     */
    private function with(
        ?CompilationUnit $unit = null,
        ?bool $targeted = null,
        ?Model $targetModel = null,
        ?array $context = null,
        ?array $visited = null,
    ): self {
        return new self(
            unit: $unit ?? $this->unit,
            queries: $this->queries,
            user: $this->user,
            targeted: $targeted ?? $this->targeted,
            /* Not `??`: clearing the model is meaningful, and every caller that
               changes the target passes both halves together. */
            targetModel: $targeted === null ? $this->targetModel : $targetModel,
            context: $context ?? $this->context,
            visited: $visited ?? $this->visited,
        );
    }
}
