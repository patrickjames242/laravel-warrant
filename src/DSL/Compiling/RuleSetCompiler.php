<?php

namespace Warrant\DSL\Compiling;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\ConditionResolver;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\Rules\WarrantRuleSet;
use Warrant\WarrantGate;
use Warrant\WarrantManager;

/**
 * Compiles a {@see WarrantRuleSet} into SQL predicates.
 *
 * There are two units of output, both nested {@see Builder} predicates ready to
 * be attached to a host query (directly for row filtering, or inside a correlated
 * subquery for per-row ability selection):
 *   - {@see compileAbility} — the predicate for one ability;
 *   - {@see compileGate} — a whole gate (a set of requested abilities plus a
 *     match mode) combined into one predicate: `ANY` → OR of each ability's
 *     predicate, `ALL` → AND. This is the single place that knows about
 *     {@see AbilityMatchMode}; the combination is no longer the caller's job.
 *
 * Per ability A the predicate is:
 *
 *     ( OR of every `can` rule's if-expression that lists A or * )
 *       AND ( AND of NOT(every `cannot` rule's if-expression that lists A or *) )
 *
 * with these hard edges (deny-overrides):
 *   - an unconditional `cannot` (null if-expression) makes A impossible → false;
 *   - an ability with no `can` rule is never granted → false;
 *   - an unconditional `can` contributes an always-true term → true.
 *
 * The walk builds a {@see CompiledWhereClauseNode} rather than writing into a
 * query builder as it goes, so a subtree that is provably true or false is a
 * real `bool` the tree can fold away — an unconditional `cannot` no longer has
 * to be frozen into a `1 = 0` that a sibling is then ANDed against. Only the
 * `compile*`/`matchesCondition` entrypoints materialize, turning a tree that
 * folded to a literal into `1 = 1`/`1 = 0` and everything else into a nested
 * predicate; the node drops the parentheses that a direct-to-builder walk is
 * forced to emit. The `*WhereClauseNode` methods hand back the unmaterialized
 * tree instead, for a caller that wants the decision rather than the SQL.
 *
 * Every condition leaf is applied inline as a nested where-group and negated
 * inline (`not (…)`, which for an author's `whereExists` is `not exists (…)`).
 * There is no EXISTS wrapping and no attempt to normalize SQL's three-valued
 * (NULL) logic: a condition compiles to exactly the SQL it emits, so an unknown
 * (NULL) row contributes no access — it never grants and never lifts a deny (the
 * safe direction; the worst case is a legitimate user blocked, never unauthorized
 * access). Because a leaf must be a spliceable boolean, a condition may only add
 * where clauses to its builder; one that emits a join/group/having/aggregate/union
 * is rejected (see {@see conditionLeaf}) — relational checks use
 * `whereExists()`/`whereNotExists()` with a correlated subquery.
 */
final class RuleSetCompiler
{
    /**
     * Hard cap on cross-schema `can(...)` nesting depth. The visited-set already
     * guarantees termination (a finite set of `(schema, ability)` pairs can never
     * repeat on one path); this is a secondary backstop against a legal but
     * pathologically deep reference chain producing enormous nested SQL.
     */
    private const MAX_CROSS_SCHEMA_DEPTH = 32;

    /**
     * @param WarrantManager|null $manager Provides the schema registry (via
     *   {@see WarrantManager::registry()}), required only to compile a
     *   {@see CrossSchemaCanNode} (resolving the referenced schema); null is fine
     *   for rule sets with no cross-schema references.
     */
    public function __construct(
        private readonly ConditionResolver $conditions,
        private readonly ?WarrantManager $manager = null,
    ) {
    }

    /**
     * Build the predicate for a whole gate — the requested abilities combined
     * under the gate's match mode — as a single nested query on $query.
     *
     * Each ability is compiled independently by {@see abilityWhereClauseNode} and the
     * results are joined here: `ALL` ANDs them (every ability must hold for a
     * row), `ANY` ORs them (any one is enough). This is the only method that
     * consults {@see AbilityMatchMode}. Joining trees rather than finished
     * predicates lets a constant cross the ability boundary — an `ANY` gate over
     * an unconditionally granted ability is just `true`, with the other
     * abilities never appearing in the SQL at all.
     *
     * An empty gate (no abilities) yields `1 = 1` — a match-all — but callers
     * short-circuit that case upstream (see the guard's `filterQuery`).
     *
     * @param list<string> $visited The `(schema, ability)` frames already on the
     *   cross-schema compile path; threaded into each ability's compile so a cycle
     *   back to any of them is detected. All abilities in one gate share the same
     *   incoming path (they are siblings, not nested references).
     */
    public function compileGate(
        Authenticatable $user,
        Builder $query,
        WarrantGate $gate,
        WarrantRuleSet $ruleSet,
        ?string $targetSqlId = null,
        array $context = [],
        array $visited = [],
    ): Builder {
        return $this->toPredicate(
            $query,
            $this->gateWhereClauseNode($user, $query, $gate, $ruleSet, $targetSqlId, $context, $visited),
        );
    }

    /**
     * The tree for a whole gate, before it is materialized — the form a caller
     * reaches for when it wants the gate's *decision* rather than its SQL.
     *
     * {@see CompiledWhereClauseNode::buildWhereClause()} folds this to the literal
     * `true`/`false` whenever the rules settled the outcome without consulting a
     * row, which is what lets a boolean check answer without a query. Materializing
     * through {@see compileGate} erases that, since a spliceable predicate has to
     * spell a constant out as `1 = 1` / `1 = 0`.
     *
     * @param list<string> $visited See {@see compileGate}.
     */
    public function gateWhereClauseNode(
        Authenticatable $user,
        Builder $query,
        WarrantGate $gate,
        WarrantRuleSet $ruleSet,
        ?string $targetSqlId = null,
        array $context = [],
        array $visited = [],
    ): CompiledWhereClauseNode {
        $gateNode = new CompiledWhereClauseNode;
        $requireAll = $gate->matchMode === AbilityMatchMode::ALL;

        foreach ($gate->abilities as $ability) {
            $abilityNode = $this->abilityWhereClauseNode($user, $query, $ability, $ruleSet, $targetSqlId, $context, $visited);

            $requireAll ? $gateNode->addAnd($abilityNode) : $gateNode->addOr($abilityNode);
        }

        return $gateNode;
    }

    /**
     * Build the predicate for a single ability as a nested query on $query.
     *
     * @param list<string> $visited The `(schema, ability)` frames already on the
     *   cross-schema compile path; a recursive call threads its parent's frames
     *   in so a cycle back to any of them is detected.
     */
    public function compileAbility(
        Authenticatable $user,
        Builder $query,
        string $ability,
        WarrantRuleSet $ruleSet,
        ?string $targetSqlId = null,
        array $context = [],
        array $visited = [],
    ): Builder {
        return $this->toPredicate(
            $query,
            $this->abilityWhereClauseNode($user, $query, $ability, $ruleSet, $targetSqlId, $context, $visited),
        );
    }

    /**
     * The tree for one ability, before it is materialized — the unit a gate ORs
     * or ANDs, and the unit a cross-schema `can(...)` splices in, so a constant
     * folds across both boundaries instead of stopping at a `1 = 1`.
     *
     * @param list<string> $visited
     */
    public function abilityWhereClauseNode(
        Authenticatable $user,
        Builder $query,
        string $ability,
        WarrantRuleSet $ruleSet,
        ?string $targetSqlId = null,
        array $context = [],
        array $visited = [],
    ): CompiledWhereClauseNode {
        $visited = $this->enterFrame($visited, $ability);

        $abilityNode = new CompiledWhereClauseNode;

        /** @var list<IBooleanExpressionNode|null> $grants */
        $grants = [];
        /** @var list<IBooleanExpressionNode|null> $denies */
        $denies = [];

        foreach ($ruleSet->rules as $rule) {
            if ($this->listsAbility($rule->canAbilities, $ability)) {
                $grants[] = $rule->conditions;
            }

            if ($this->listsAbility($rule->cannotAbilities(), $ability)) {
                $denies[] = $rule->conditions;
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

        $grantCtx = new CompilationContext($user, $targetSqlId, $context, visited: $visited);

        // Grant side: OR of every can-expression (null => always-true term).
        $grantGroup = new CompiledWhereClauseNode;

        foreach ($grants as $grantExpression) {
            $grantGroup->addOr(
                $grantExpression === null ? true : $this->expression($grantExpression, $grantCtx, $query),
            );
        }

        $abilityNode->addAnd($grantGroup);

        // Deny side: AND NOT(expression) for each conditional `cannot`.
        $denyCtx = new CompilationContext($user, $targetSqlId, $context, negate: true, visited: $visited);

        foreach ($denies as $denyExpression) {
            $abilityNode->addAnd($this->expression($denyExpression, $denyCtx, $query));
        }

        return $abilityNode;
    }

    /**
     * Build a predicate that is true for the target row iff $condition matches —
     * an expression tree compiled in isolation, without the deny-overrides formula.
     *
     * Two callers: the singular-target denial diagnostic (does one `cannot` rule's
     * condition fire for the target?), and {@see crossSchemaCheckLeaf}, which uses
     * it to compile a `check(...)` predicate against the *target* schema's resolver.
     * A null condition (an unconditional `cannot`) always matches. Reuses the same
     * inline leaf, targeted-vs-global, and `@context` semantics as
     * {@see compileAbility}, so a re-run agrees exactly with the live check.
     */
    public function matchesCondition(
        Authenticatable $user,
        Builder $query,
        ?IBooleanExpressionNode $condition,
        ?string $targetSqlId = null,
        array $context = [],
    ): Builder {
        return $this->toPredicate(
            $query,
            $this->conditionWhereClauseNode($user, $query, $condition, $targetSqlId, $context),
        );
    }

    /**
     * The tree for a standalone condition, before it is materialized — see
     * {@see abilityWhereClauseNode} for why the unmaterialized form is worth having.
     */
    public function conditionWhereClauseNode(
        Authenticatable $user,
        Builder $query,
        ?IBooleanExpressionNode $condition,
        ?string $targetSqlId = null,
        array $context = [],
    ): CompiledWhereClauseNode {
        $conditionNode = new CompiledWhereClauseNode;

        if ($condition === null) {
            return $conditionNode->addAnd(true);
        }

        return $conditionNode->addAnd(
            $this->expression($condition, new CompilationContext($user, $targetSqlId, $context), $query),
        );
    }

    /**
     * Materialize a folded where clause into the nested predicate the public API
     * returns.
     *
     * A clause that folded to a literal becomes `1 = 1`/`1 = 0`: callers splice
     * the result with `addNestedWhereQuery`, which skips a query holding no
     * where clause, so an always-true predicate has to say so out loud.
     *
     * Public because a caller that folded a node itself — to read the decision
     * before deciding whether SQL is needed at all — still has to be able to
     * spell the result out when it turns out SQL *is* needed, without compiling
     * the tree a second time.
     */
    public function materializeWhereClause(Builder $query, bool|Builder $whereClause): Builder
    {
        return is_bool($whereClause)
            ? $query->newQuery()->whereRaw($whereClause ? '1 = 1' : '1 = 0')
            : $whereClause;
    }

    /**
     * Fold a tree and materialize whatever it decided.
     */
    private function toPredicate(Builder $query, CompiledWhereClauseNode $node): Builder
    {
        return $this->materializeWhereClause($query, $node->buildWhereClause($query));
    }

    /**
     * @param array<int, string> $abilities
     */
    private function listsAbility(array $abilities, string $ability): bool
    {
        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }

    /**
     * Build the tree for $node, negating via De Morgan so that negation always
     * lands on the leaves, where a negated leaf is applied inline as `not (…)`
     * (for an author's `whereExists`, that reads as `not exists (…)`).
     *
     * $host is the query the leaves are built off — never appended to; a leaf's
     * own connector is decided by the operand it becomes, not by its position.
     */
    private function expression(IBooleanExpressionNode $node, CompilationContext $ctx, Builder $host): CompiledWhereClauseNode
    {
        if ($node instanceof NotNode) {
            return $this->expression($node->operand, $ctx->negated(), $host);
        }

        if ($node instanceof AndNode || $node instanceof OrNode) {
            // NOT(a AND b) = NOT a OR NOT b ; NOT(a OR b) = NOT a AND NOT b.
            $childrenAreOr = $node instanceof OrNode;

            $group = (new CompiledWhereClauseNode)->addAnd($this->expression($node->leftSide, $ctx, $host));
            $rightSide = $this->expression($node->rightSide, $ctx, $host);

            return ($childrenAreOr xor $ctx->negate)
                ? $group->addOr($rightSide)
                : $group->addAnd($rightSide);
        }

        if ($node instanceof ConditionNode) {
            return $this->conditionLeaf($node, $ctx, $host);
        }

        if ($node instanceof CrossSchemaCanNode) {
            return $this->crossSchemaCanLeaf($node, $ctx, $host);
        }

        if ($node instanceof CrossSchemaConditionNode) {
            return $this->crossSchemaCheckLeaf($node, $ctx, $host);
        }

        if ($node instanceof BooleanNode) {
            return (new CompiledWhereClauseNode)->addAnd($node->value, negated: $ctx->negate);
        }

        throw new InvalidArgumentException(sprintf('Unsupported expression node [%s].', $node::class));
    }

    /**
     * Push this compile's `(schema, ability)` frame onto the visited path,
     * detecting a cross-schema cycle (the frame already present) and enforcing
     * the depth cap.
     *
     * @param list<string> $visited
     * @return list<string>
     */
    private function enterFrame(array $visited, string $ability): array
    {
        /* Framed by class string rather than schema key: the class identifies the
           schema without a reverse lookup. forPath() maps them back to keys when
           it builds the message. */
        $frame = $this->conditions::class . "\0" . $ability;

        if (in_array($frame, $visited, true)) {
            throw CrossSchemaCycleException::forPath(
                array_map($this->describeFrame(...), [...$visited, $frame]),
            );
        }

        $visited[] = $frame;

        if (count($visited) > self::MAX_CROSS_SCHEMA_DEPTH) {
            throw new RuntimeException(sprintf(
                'Cross-schema can(...) nesting exceeded the maximum depth of %d.',
                self::MAX_CROSS_SCHEMA_DEPTH,
            ));
        }

        return $visited;
    }

    /**
     * Render a `(schema class, ability)` frame as `schemaKey:ability` for the cycle
     * message. Falls back to the class string when there is no registry to ask —
     * a compiler built without a manager cannot have crossed schemas anyway.
     */
    private function describeFrame(string $frame): string
    {
        [$schemaClass, $ability] = explode("\0", $frame, 2);

        $schemaKey = $this->manager?->registry()->resolveSchemaKeyOrFail($schemaClass) ?? $schemaClass;

        return $schemaKey . ':' . $ability;
    }

    /**
     * Compile a cross-schema `can(<ability> for <schema>[(<row>)] [with <map>])`
     * by recursively compiling the referenced schema B's ability and embedding it:
     * a row-bound reference wraps B's per-row predicate as `EXISTS` over B's table;
     * an unbound reference splices B's no-target boolean predicate inline. B sees
     * only the explicit `with` map as its context — never A's ambient context.
     */
    private function crossSchemaCanLeaf(CrossSchemaCanNode $node, CompilationContext $ctx, Builder $host): CompiledWhereClauseNode
    {
        if ($this->manager === null) {
            throw new InvalidArgumentException(sprintf(
                'Compiling a can(...) reference to schema [%s] requires the schema registry; '
                    .'construct RuleSetCompiler with a WarrantManager.',
                $node->schemaKey,
            ));
        }

        /** @var class-string<\Warrant\Schema\WarrantSchema> $bClass */
        $bClass = $this->manager->registry()->resolveSchemaClassOrFail($node->schemaKey);
        $bSchema = new $bClass;

        // Explicit boundary context only: resolve each with-map RHS against A's
        // context, with no ambient inheritance of A's bag.
        $bContext = [];
        foreach ($node->contextMap as $key => $value) {
            $bContext[$key] = $this->resolveArgValue($host, $value, $ctx->checkContext);
        }

        $bRuleSet = $this->manager->forSchema($bClass, $ctx->user)->resolvedRuleSet();
        $bCompiler = new self($bSchema, $this->manager);

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($host, $bModel, $node->schemaKey);

            $rowId = $this->resolveArgValue($host, $node->boundRow, $ctx->checkContext);

            $bSubquery = $host->newQuery()
                ->from($bModel->getTable())
                ->where($bModel->getQualifiedKeyName(), '=', $rowId);

            $predicate = $bCompiler->compileAbility(
                $ctx->user,
                $bSubquery,
                $node->ability,
                $bRuleSet,
                $bModel->getQualifiedKeyName(),
                $bContext,
                $ctx->visited,
            );

            $bSubquery->addNestedWhereQuery($predicate);

            // The exists goes on a leaf of its own, already carrying its negation,
            // so it is a one-clause leaf the tree lifts back out without adding a
            // group — `exists (…)` / `not exists (…)`, as before.
            $existsLeaf = $host->newQuery();
            $existsLeaf->addWhereExistsQuery($bSubquery, 'and', $ctx->negate);

            return (new CompiledWhereClauseNode)->addAnd($existsLeaf);
        }

        // Unbound / no-target: row conditions in B are forced false; the result is
        // a correlation-free boolean tree spliced inline (negation-aware), so a B
        // that decides outright folds into A instead of stopping at a `1 = 0`.
        return (new CompiledWhereClauseNode)->addAnd(
            $bCompiler->abilityWhereClauseNode(
                $ctx->user,
                $host,
                $node->ability,
                $bRuleSet,
                null,
                $bContext,
                $ctx->visited,
            ),
            negated: $ctx->negate,
        );
    }

    /**
     * Compile a cross-schema `check(<predicate> for <schema>[(<row>)] [with <map>])`
     * by dispatching the target schema B's conditions and splicing the emitted SQL.
     * Unlike {@see crossSchemaCanLeaf} it never compiles B's *rules* — it is pure
     * condition dispatch, so it carries no cycle risk and needs no visited-set. A
     * row-bound reference wraps B's predicate as `EXISTS` over B's table
     * (`NOT EXISTS` when negated); an unbound reference splices B's boolean predicate
     * inline. The predicate's condition leaves are compiled with B's own resolver,
     * and B sees only the explicit `with` map as its context — never A's ambient bag.
     */
    private function crossSchemaCheckLeaf(CrossSchemaConditionNode $node, CompilationContext $ctx, Builder $host): CompiledWhereClauseNode
    {
        if ($this->manager === null) {
            throw new InvalidArgumentException(sprintf(
                'Compiling a check(...) reference to schema [%s] requires the schema registry; '
                    .'construct RuleSetCompiler with a WarrantManager.',
                $node->schemaKey,
            ));
        }

        /** @var class-string<\Warrant\Schema\WarrantSchema> $bClass */
        $bClass = $this->manager->registry()->resolveSchemaClassOrFail($node->schemaKey);
        $bSchema = new $bClass;

        // Explicit boundary context only: resolve each with-map RHS against A's
        // context, with no ambient inheritance of A's bag.
        $bContext = [];
        foreach ($node->contextMap as $key => $value) {
            $bContext[$key] = $this->resolveArgValue($host, $value, $ctx->checkContext);
        }

        // Compile the predicate with B's own resolver, so its condition leaves emit
        // B's SQL. conditionWhereClauseNode() walks an expression subtree in isolation.
        $bCompiler = new self($bSchema, $this->manager);

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($host, $bModel, $node->schemaKey);

            $rowId = $this->resolveArgValue($host, $node->boundRow, $ctx->checkContext);

            $bSubquery = $host->newQuery()
                ->from($bModel->getTable())
                ->where($bModel->getQualifiedKeyName(), '=', $rowId);

            $predicate = $bCompiler->matchesCondition(
                $ctx->user,
                $bSubquery,
                $node->predicate,
                $bModel->getQualifiedKeyName(),
                $bContext,
            );

            $bSubquery->addNestedWhereQuery($predicate);

            // As in a row-bound can(...): the exists is its own one-clause leaf,
            // already negated, so the tree lifts it back out without a group.
            $existsLeaf = $host->newQuery();
            $existsLeaf->addWhereExistsQuery($bSubquery, 'and', $ctx->negate);

            return (new CompiledWhereClauseNode)->addAnd($existsLeaf);
        }

        // Unbound / no-target: row conditions in B are forced false (validation
        // already forbids them here); the result is a correlation-free boolean
        // tree spliced inline (negation-aware).
        return (new CompiledWhereClauseNode)->addAnd(
            $bCompiler->conditionWhereClauseNode($ctx->user, $host, $node->predicate, null, $bContext),
            negated: $ctx->negate,
        );
    }

    /**
     * A cross-schema `can(...)` embeds B's table as a subquery inside A's query,
     * which executes on a single connection. If B lives on a different connection
     * the emitted SQL would silently reference a table that isn't there, so reject
     * it with a clear message instead.
     */
    private function assertSameConnection(Builder $host, Model $bModel, string $bSchemaKey): void
    {
        $parentConnection = $host->getConnection()->getName();
        $bConnection = $bModel->getConnection()->getName();

        if ($parentConnection !== $bConnection) {
            throw new InvalidArgumentException(sprintf(
                'Cannot compile can(... for %s): that schema is on database connection [%s] but the '
                    .'query runs on [%s]; cross-connection can(...) is not supported.',
                $bSchemaKey,
                $bConnection,
                $parentConnection,
            ));
        }
    }

    /**
     * Resolve one symbolic DSL argument to its concrete value for compilation.
     * A {@see ContextRef} is filled from the check-time context (absent → null); a
     * {@see ColumnRef} becomes a grammar-wrapped {@see Expression} for a real table
     * column. Any already-concrete value (literals, resolved bindings) passes
     * straight through. Shared by condition parameters and the cross-schema handle
     * row selector / `with` map so all three resolve identically.
     *
     * @param array<string, mixed> $checkContext
     */
    private function resolveArgValue(Builder $query, mixed $value, array $checkContext): mixed
    {
        if ($value instanceof ContextRef) {
            return $checkContext[$value->key] ?? null;
        }

        if ($value instanceof ColumnRef) {
            return $this->resolveColumnRef($query, $value);
        }

        if ($value instanceof SqlRef) {
            // Always parenthesize (even if the author already did): a bare
            // `select ...` is then valid as a scalar subquery in a comparison.
            return new Expression('(' . $value->sql . ')');
        }

        return $value;
    }

    /**
     * Resolve a `@column <schema>.<column>` reference to an {@see Expression} of
     * the grammar-wrapped `<realTable>.<column>` identifier (e.g.
     * `` `timesheets`.`pay_period_id` ``). The schema key is mapped to its model's
     * real table via the registry — the key is not always the table name — and the
     * identifier is quoted with the query's own grammar so it is emitted verbatim,
     * never re-wrapped or bound as a value.
     *
     * It is the rule author's responsibility that the referenced table is in scope
     * in the surrounding SQL (the owning schema's own filter, or the outer query of
     * a `check(...)`/`can(...)` correlated subquery); an unrelated table yields a
     * SQL error at execution.
     */
    private function resolveColumnRef(Builder $query, ColumnRef $ref): Expression
    {
        if ($this->manager === null) {
            throw new InvalidArgumentException(sprintf(
                'Resolving a @column reference to schema [%s] requires the schema registry; '
                    .'construct RuleSetCompiler with a WarrantManager.',
                $ref->schemaKey,
            ));
        }

        try {
            $schemaClass = $this->manager->registry()->resolveSchemaClassOrFail($ref->schemaKey);
        } catch (OutOfBoundsException $e) {
            throw new InvalidArgumentException(
                sprintf('A @column reference targets unknown schema [%s].', $ref->schemaKey),
                previous: $e,
            );
        }

        if ($schemaClass::model === '') {
            throw new InvalidArgumentException(sprintf(
                'A @column reference targets schema [%s], which has no model and therefore no table; '
                    .'@column can only reference a model-backed schema.',
                $ref->schemaKey,
            ));
        }

        /** @var Model $model */
        $model = new ($schemaClass::model);

        return new Expression($query->getGrammar()->wrap($model->getTable() . '.' . $ref->column));
    }

    private function conditionLeaf(ConditionNode $node, CompilationContext $ctx, Builder $host): CompiledWhereClauseNode
    {
        // A row condition cannot be evaluated without a row; force it false
        // (so `not <row-condition>` becomes true) in a no-target compile.
        if ($ctx->targetSqlId === null && ($this->conditions->getConditionDefinition($node->conditionKey)?->isRow ?? false)) {
            return (new CompiledWhereClauseNode)->addAnd(false, negated: $ctx->negate);
        }

        // Resolve any symbolic argument placeholder. A @context ref is filled from
        // the check-time context — an absent key (only ever a non-required one;
        // required keys are enforced before compilation) resolves to null and is
        // passed to the condition as that argument's value, leaving the condition
        // to decide what null means (rather than the compiler forcing the whole
        // leaf false), so conditions reading a possibly-absent @context arg must
        // tolerate null. A @column ref is resolved to a grammar-wrapped Expression
        // for the referenced schema's real table column.
        $parameters = [];
        foreach ($node->parameters as $parameter) {
            $parameters[] = $this->resolveArgValue($host, $parameter, $ctx->checkContext);
        }

        $conditionQuery = $host->newQuery();

        $result = $this->conditions->applyCondition(
            $node->conditionKey,
            $ctx->user,
            $conditionQuery,
            $ctx->targetSqlId,
            $parameters,
            $ctx->checkContext,
        );

        // A no-target condition may decide the outcome outright.
        if (is_bool($result)) {
            return (new CompiledWhereClauseNode)->addAnd($result, negated: $ctx->negate);
        }

        // A condition must be a spliceable boolean, so it may only add where
        // clauses. Anything that changes the query's row shape — a join, group,
        // having, aggregate, or union — cannot be inlined, ANDed/ORed, or negated
        // in place; reject it with a clear message pointing at whereExists().
        $this->assertOnlyWhereClauses($conditionQuery, $node->conditionKey);
        $this->assertAddedAWhereClause($conditionQuery, $node->conditionKey);

        // The condition's where-group becomes a leaf, applied inline. Negation
        // rides along on the operand and lands as a `not (…)` nested group — the
        // same way Laravel's whereNot composes its boolean — so a scalar leaf
        // follows SQL's three-valued logic and an author's whereExists reads as
        // `not exists (…)`.
        return (new CompiledWhereClauseNode)->addAnd($conditionQuery, negated: $ctx->negate);
    }

    /**
     * A condition that added no where clause emitted nothing at all, which would
     * silently mean "match every row" — almost always an author's forgotten
     * branch rather than an intent to grant everything. A condition that really
     * does decide the outcome should say so by returning a bool.
     */
    private function assertAddedAWhereClause(Builder $conditionQuery, string $conditionKey): void
    {
        if ($conditionQuery->wheres !== []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Condition [%s] on schema [%s] added no where clause; a condition must add at least one '
                .'where clause, or return true/false to decide the outcome outright.',
            $conditionKey,
            $this->conditions::class,
        ));
    }

    /**
     * A condition leaf must compile to a boolean the compiler can splice into the
     * deny-overrides predicate. Only where clauses qualify; a join, group, having,
     * aggregate, or union changes the query's row shape and cannot be inlined or
     * negated in place. Relational checks must use `whereExists()`/`whereNotExists()`
     * with a correlated subquery instead (their inner joins live on the subquery,
     * not on this builder, so they are allowed).
     */
    private function assertOnlyWhereClauses(Builder $conditionQuery, string $conditionKey): void
    {
        $offending = match (true) {
            ! empty($conditionQuery->joins) => 'join',
            ! empty($conditionQuery->groups) => 'group by',
            ! empty($conditionQuery->havings) => 'having',
            ! empty($conditionQuery->unions) => 'union',
            $conditionQuery->aggregate !== null => 'aggregate',
            default => null,
        };

        if ($offending === null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Condition [%s] on schema [%s] may only add where clauses, but it emitted a [%s]; '
                .'use whereExists()/whereNotExists() with a correlated subquery instead of join()/groupBy()/having().',
            $conditionKey,
            $this->conditions::class,
            $offending,
        ));
    }
}
