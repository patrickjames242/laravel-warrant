<?php

namespace Warrant\RuleSyntaxTree;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;
use Warrant\AbilityMatchMode;
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
 *   - an unconditional `cannot` (null if-expression) makes A impossible → 1 = 0;
 *   - an ability with no `can` rule is never granted → 1 = 0;
 *   - an unconditional `can` contributes an always-true term → 1 = 1.
 *
 * Every condition leaf is applied inline as a nested where-group and negated
 * inline (`not (…)`, which for an author's `whereExists` is `not exists (…)`).
 * There is no EXISTS wrapping and no attempt to normalize SQL's three-valued
 * (NULL) logic: a condition compiles to exactly the SQL it emits, so an unknown
 * (NULL) row contributes no access — it never grants and never lifts a deny (the
 * safe direction; the worst case is a legitimate user blocked, never unauthorized
 * access). Because a leaf must be a spliceable boolean, a condition may only add
 * where clauses to its builder; one that emits a join/group/having/aggregate/union
 * is rejected (see {@see applyCondition}) — relational checks use
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
     * Each ability is compiled independently by {@see compileAbility} and the
     * results are joined here: `ALL` ANDs them (every ability must hold for a
     * row), `ANY` ORs them (any one is enough). This is the only method that
     * consults {@see AbilityMatchMode}; splicing this predicate is equivalent to
     * the old per-ability loop that lived in the guard's `filterQuery`, so the
     * emitted SQL is unchanged.
     *
     * An empty gate (no abilities) yields an empty predicate — no where clauses,
     * i.e. a match-all — but callers normally short-circuit that case upstream.
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
        $predicate = $query->newQuery();
        $connector = $gate->matchMode === AbilityMatchMode::ALL ? 'and' : 'or';

        foreach ($gate->abilities as $ability) {
            $predicate->addNestedWhereQuery(
                $this->compileAbility($user, $query, $ability, $ruleSet, $targetSqlId, $context, $visited),
                $connector,
            );
        }

        return $predicate;
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
        $visited = $this->enterFrame($visited, $ability);

        $predicate = $query->newQuery();

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
                return $predicate->whereRaw('1 = 0');
            }
        }

        // No `can` rule grants this ability.
        if ($grants === []) {
            return $predicate->whereRaw('1 = 0');
        }

        $grantCtx = new CompilationContext($user, $targetSqlId, $context, visited: $visited);

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
        $denyCtx = new CompilationContext($user, $targetSqlId, $context, negate: true, visited: $visited);

        foreach ($denies as $denyExpression) {
            $predicate->where(function (Builder $denyGroup) use ($denyExpression, $denyCtx): void {
                $this->applyExpression($denyGroup, $denyExpression, $denyCtx);
            });
        }

        return $predicate;
    }

    /**
     * Build a predicate that is true for the target row iff $condition matches —
     * an expression tree compiled in isolation, without the deny-overrides formula.
     *
     * Two callers: the singular-target denial diagnostic (does one `cannot` rule's
     * condition fire for the target?), and {@see applyCrossSchemaCheck}, which uses
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
        $predicate = $query->newQuery();

        if ($condition === null) {
            return $predicate->whereRaw('1 = 1');
        }

        $this->applyExpression(
            $predicate,
            $condition,
            new CompilationContext($user, $targetSqlId, $context),
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
     * negating via De Morgan so that negation always lands on the leaves, where a
     * negated leaf is applied inline as `not (…)` (for an author's `whereExists`,
     * that reads as `not exists (…)`).
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

        if ($node instanceof CrossSchemaCanNode) {
            $this->applyCrossSchemaCan($parent, $node, $ctx);

            return;
        }

        if ($node instanceof CrossSchemaConditionNode) {
            $this->applyCrossSchemaCheck($parent, $node, $ctx);

            return;
        }

        if ($node instanceof BooleanNode) {
            $value = $ctx->negate ? ! $node->value : $node->value;
            $parent->whereRaw($value ? '1 = 1' : '1 = 0', [], $ctx->boolean);

            return;
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
        $frame = $this->conditions::schemaKey() . "\0" . $ability;

        if (in_array($frame, $visited, true)) {
            throw CrossSchemaCycleException::forPath(
                array_map(fn (string $f): string => str_replace("\0", ':', $f), [...$visited, $frame]),
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
     * Compile a cross-schema `can(<ability> for <schema>[(<row>)] [with <map>])`
     * by recursively compiling the referenced schema B's ability and embedding it:
     * a row-bound reference wraps B's per-row predicate as `EXISTS` over B's table;
     * an unbound reference splices B's no-target boolean predicate inline. B sees
     * only the explicit `with` map as its context — never A's ambient context.
     */
    private function applyCrossSchemaCan(Builder $parent, CrossSchemaCanNode $node, CompilationContext $ctx): void
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
            $bContext[$key] = $this->resolveArgValue($parent, $value, $ctx->checkContext);
        }

        $bRuleSet = $this->manager->forSchema($bClass, $ctx->user)->resolvedRuleSet();
        $bCompiler = new self($bSchema, $this->manager);

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($parent, $bModel, $node->schemaKey);

            $rowId = $this->resolveArgValue($parent, $node->boundRow, $ctx->checkContext);

            $bSubquery = $parent->newQuery()
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
            $parent->addWhereExistsQuery($bSubquery, $ctx->boolean, $ctx->negate);

            return;
        }

        // Unbound / no-target: row conditions in B are forced false; the result is
        // a correlation-free boolean predicate spliced inline (negation-aware).
        $predicate = $bCompiler->compileAbility(
            $ctx->user,
            $parent,
            $node->ability,
            $bRuleSet,
            null,
            $bContext,
            $ctx->visited,
        );

        $parent->addNestedWhereQuery($predicate, $ctx->negate ? "{$ctx->boolean} not" : $ctx->boolean);
    }

    /**
     * Compile a cross-schema `check(<predicate> for <schema>[(<row>)] [with <map>])`
     * by dispatching the target schema B's conditions and splicing the emitted SQL.
     * Unlike {@see applyCrossSchemaCan} it never compiles B's *rules* — it is pure
     * condition dispatch, so it carries no cycle risk and needs no visited-set. A
     * row-bound reference wraps B's predicate as `EXISTS` over B's table
     * (`NOT EXISTS` when negated); an unbound reference splices B's boolean predicate
     * inline. The predicate's condition leaves are compiled with B's own resolver,
     * and B sees only the explicit `with` map as its context — never A's ambient bag.
     */
    private function applyCrossSchemaCheck(Builder $parent, CrossSchemaConditionNode $node, CompilationContext $ctx): void
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
            $bContext[$key] = $this->resolveArgValue($parent, $value, $ctx->checkContext);
        }

        // Compile the predicate with B's own resolver, so its condition leaves emit
        // B's SQL. matchesCondition() walks an expression subtree in isolation.
        $bCompiler = new self($bSchema, $this->manager);

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($parent, $bModel, $node->schemaKey);

            $rowId = $this->resolveArgValue($parent, $node->boundRow, $ctx->checkContext);

            $bSubquery = $parent->newQuery()
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
            $parent->addWhereExistsQuery($bSubquery, $ctx->boolean, $ctx->negate);

            return;
        }

        // Unbound / no-target: row conditions in B are forced false (validation
        // already forbids them here); the result is a correlation-free boolean
        // predicate spliced inline (negation-aware).
        $predicate = $bCompiler->matchesCondition(
            $ctx->user,
            $parent,
            $node->predicate,
            null,
            $bContext,
        );

        $parent->addNestedWhereQuery($predicate, $ctx->negate ? "{$ctx->boolean} not" : $ctx->boolean);
    }

    /**
     * A cross-schema `can(...)` embeds B's table as a subquery inside A's query,
     * which executes on a single connection. If B lives on a different connection
     * the emitted SQL would silently reference a table that isn't there, so reject
     * it with a clear message instead.
     */
    private function assertSameConnection(Builder $parent, Model $bModel, string $bSchemaKey): void
    {
        $parentConnection = $parent->getConnection()->getName();
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

    private function applyCondition(Builder $parent, ConditionNode $node, CompilationContext $ctx): void
    {
        // A row condition cannot be evaluated without a row; force it false
        // (so `not <row-condition>` becomes true) in a no-target compile.
        if ($ctx->targetSqlId === null && ($this->conditions->getConditionDefinition($node->conditionKey)?->isRow ?? false)) {
            $parent->whereRaw($ctx->negate ? '1 = 1' : '1 = 0', [], $ctx->boolean);

            return;
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
            $parameters[] = $this->resolveArgValue($parent, $parameter, $ctx->checkContext);
        }

        $conditionQuery = $parent->newQuery();

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
            $value = $ctx->negate ? ! $result : $result;
            $parent->whereRaw($value ? '1 = 1' : '1 = 0', [], $ctx->boolean);

            return;
        }

        // A condition must be a spliceable boolean, so it may only add where
        // clauses. Anything that changes the query's row shape — a join, group,
        // having, aggregate, or union — cannot be inlined, ANDed/ORed, or negated
        // in place; reject it with a clear message pointing at whereExists().
        $this->assertOnlyWhereClauses($conditionQuery, $node->conditionKey);

        // A condition that added no where at all means "match every row" — an
        // always-true term (or always-false when negated). Inlining an empty
        // group would contribute nothing and wrongly vanish from an OR.
        if (empty($conditionQuery->wheres)) {
            $parent->whereRaw($ctx->negate ? '1 = 0' : '1 = 1', [], $ctx->boolean);

            return;
        }

        // Apply the condition's where-group inline. Negation lands here as a
        // `not (…)` nested group — the same way Laravel's whereNot composes its
        // boolean — so a scalar leaf follows SQL's three-valued logic and an
        // author's whereExists reads as `not exists (…)`.
        $parent->addNestedWhereQuery(
            $conditionQuery,
            $ctx->negate ? "{$ctx->boolean} not" : $ctx->boolean,
        );
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
