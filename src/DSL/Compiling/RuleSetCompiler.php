<?php

namespace Warrant\DSL\Compiling;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\Units\AbilityUnit;
use Warrant\DSL\Compiling\Units\ConditionUnit;
use Warrant\DSL\Compiling\Units\GateUnit;
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
use Warrant\WarrantManager;

/**
 * Compiles a {@see WarrantRuleSet} into SQL predicates.
 *
 * One way in — {@see compile()} — taking a {@see CompilationInput} and returning
 * a {@see CompilationResult}. The input names what to compile (a
 * {@see \Warrant\DSL\Compiling\Units\CompilationUnit}: a gate, one ability, or a
 * bare condition) along with the facts common to all three; the result offers the
 * compile in whichever form the caller needs, folded or as SQL.
 *
 * The three units:
 *   - {@see AbilityUnit} — the predicate for one ability;
 *   - {@see GateUnit} — a whole gate (a set of requested abilities plus a
 *     match mode) combined into one predicate: `ANY` → OR of each ability's
 *     predicate, `ALL` → AND. This is the single place that knows about
 *     {@see AbilityMatchMode}; the combination is no longer the caller's job.
 *   - {@see ConditionUnit} — an expression tree compiled in isolation, with no
 *     rules applied at all.
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
 * to be frozen into a `1 = 0` that a sibling is then ANDed against. Nothing here
 * materializes: {@see CompilationResult} does that, on demand, turning a tree
 * that folded to a literal into `1 = 1`/`1 = 0` and everything else into a
 * nested predicate, while the node drops the parentheses that a direct-to-builder
 * walk is forced to emit. A caller that wants the decision rather than the SQL
 * reads {@see CompilationResult::decision()} and the constant never becomes a
 * query at all.
 *
 * The target row's SQL identity is derived here, from the resolver's own model
 * ({@see ConditionResolver::modelClass()}) — callers say only *whether* a row is
 * in scope, via {@see CompilationInput::forTargetRow()}. That is the half they
 * genuinely know and the compiler cannot: a predicate is detached, and where it
 * is eventually spliced is the caller's business.
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
     * Compile one unit into a predicate.
     *
     * The single way in. Which of the three units the input carries decides how
     * the rules are applied; everything else — the query factory the leaves are
     * built from, the user, whether a row is in scope, the check-time context —
     * is the same in all three cases and comes off the input unchanged.
     */
    public function compile(CompilationInput $input): CompilationResult
    {
        $unit = $input->unit;

        $node = match (true) {
            $unit instanceof GateUnit => $this->gateNode($input, $unit),
            $unit instanceof AbilityUnit => $this->abilityNode($input, $unit),
            $unit instanceof ConditionUnit => $this->conditionNode($input, $unit),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported compilation unit [%s].', $unit::class),
            ),
        };

        return new CompilationResult($node, $input->queries);
    }

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
    private function gateNode(CompilationInput $input, GateUnit $unit): CompiledWhereClauseNode
    {
        $gateNode = new CompiledWhereClauseNode;
        $requireAll = $unit->gate->matchMode === AbilityMatchMode::ALL;

        foreach ($unit->gate->abilities as $ability) {
            $abilityNode = $this->abilityNode($input, new AbilityUnit($ability, $unit->ruleSet));

            $requireAll ? $gateNode->addAnd($abilityNode) : $gateNode->addOr($abilityNode);
        }

        return $gateNode;
    }

    /**
     * The tree for one ability — the unit a gate ORs or ANDs, and the unit a
     * cross-schema `can(...)` splices in, so a constant folds across both
     * boundaries instead of stopping at a `1 = 1`.
     */
    private function abilityNode(CompilationInput $input, AbilityUnit $unit): CompiledWhereClauseNode
    {
        $ability = $unit->ability;
        $ruleSet = $unit->ruleSet;
        $visited = $this->enterFrame($input->visited, $ability);

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

        $grantCtx = $this->context($input, visited: $visited);

        // Grant side: OR of every can-expression (null => always-true term).
        $grantGroup = new CompiledWhereClauseNode;

        foreach ($grants as $grantExpression) {
            $grantGroup->addOr(
                $grantExpression === null ? true : $this->expression($grantExpression, $grantCtx),
            );
        }

        $abilityNode->addAnd($grantGroup);

        // Deny side: AND NOT(expression) for each conditional `cannot`.
        $denyCtx = $this->context($input, negate: true, visited: $visited);

        foreach ($denies as $denyExpression) {
            $abilityNode->addAnd($this->expression($denyExpression, $denyCtx));
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
    private function conditionNode(CompilationInput $input, ConditionUnit $unit): CompiledWhereClauseNode
    {
        $conditionNode = new CompiledWhereClauseNode;

        if ($unit->condition === null) {
            return $conditionNode->addAnd(true);
        }

        return $conditionNode->addAnd(
            $this->expression($unit->condition, $this->context($input)),
        );
    }

    /**
     * The walk state for one compile, with the target row's SQL identity derived
     * from the resolver's own model.
     *
     * A capability schema has no model and therefore no row, so a compile against
     * one is never targeted no matter what the input asked for — the same
     * conclusion validation reaches, arrived at here so a row condition folds to
     * `false` rather than emitting a reference to a table that does not exist.
     *
     * @param list<string> $visited
     */
    private function context(CompilationInput $input, bool $negate = false, array $visited = []): CompilationContext
    {
        return new CompilationContext(
            user: $input->user,
            queries: $input->queries,
            targetSqlId: $this->targetSqlId($input),
            checkContext: $input->context,
            negate: $negate,
            visited: $visited === [] ? $input->visited : $visited,
            targetModel: $input->targetModel,
        );
    }

    /**
     * The target row's qualified key, or null when no row is in scope.
     *
     * Derived rather than supplied: {@see ResolvesConditions} builds a row
     * condition's {@see \Warrant\Schema\Conditions\RowConditionContext} from this
     * same model, so anything a caller passed would be re-derived and discarded.
     */
    private function targetSqlId(CompilationInput $input): ?string
    {
        if (! $input->targeted) {
            return null;
        }

        $modelClass = $this->conditions::modelClass();

        if ($modelClass === '') {
            return null;
        }

        /** @var Model $model */
        $model = new $modelClass;

        return $model->getQualifiedKeyName();
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
     * Leaves are built off `$ctx->queries`, which hands out fresh builders and
     * nothing else; a leaf's own connector is decided by the operand it becomes,
     * not by its position.
     */
    private function expression(IBooleanExpressionNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        if ($node instanceof NotNode) {
            return $this->expression($node->operand, $ctx->negated());
        }

        if ($node instanceof AndNode || $node instanceof OrNode) {
            // NOT(a AND b) = NOT a OR NOT b ; NOT(a OR b) = NOT a AND NOT b.
            $childrenAreOr = $node instanceof OrNode;

            $group = (new CompiledWhereClauseNode)->addAnd($this->expression($node->leftSide, $ctx));
            $rightSide = $this->expression($node->rightSide, $ctx);

            return ($childrenAreOr xor $ctx->negate)
                ? $group->addOr($rightSide)
                : $group->addAnd($rightSide);
        }

        if ($node instanceof ConditionNode) {
            return $this->conditionLeaf($node, $ctx);
        }

        if ($node instanceof CrossSchemaCanNode) {
            return $this->crossSchemaCanLeaf($node, $ctx);
        }

        if ($node instanceof CrossSchemaConditionNode) {
            return $this->crossSchemaCheckLeaf($node, $ctx);
        }

        if ($node instanceof BooleanNode) {
            return (new CompiledWhereClauseNode)->addAnd($node->value, negated: $ctx->negate);
        }

        throw new InvalidArgumentException(sprintf('Unsupported expression node [%s].', $node::class));
    }


    /**
     * Read a cross-schema handle's row selector into the key to bind and, when
     * the caller named the row by handing over the row itself, the model to
     * evaluate B's row conditions against.
     *
     * The selector is whatever a binding or `@context` supplied, and nothing has
     * validated it: it lands verbatim as the bound value of
     * `where <b>.<key> = ?`. A database driver only takes scalars, so an object
     * with no defined meaning there reaches PDO and is stringified by whatever
     * `__toString()` it happens to have — for an Eloquent model that is
     * `toJson()`, which compiles to `where "folders"."id" = '{"id":"f-1"}'` and
     * quietly matches no row at all. Rejecting those is the point of this method:
     * a rule that cannot work should say so rather than silently deny.
     *
     * A model of B's own class is the case worth having. It names the row by
     * being it, so its key is bound — and if Eloquent regards it as hydrated it
     * is also handed to B's row conditions, which may then answer in PHP. A model
     * of any *other* class is a mistake worth catching: its key would be compared
     * against the wrong table, which is exactly the silent non-match this method
     * exists to end.
     *
     * Three object types pass through because they already mean something as a
     * binding: an {@see Expression} is `@column` / `@sql` splicing raw SQL rather
     * than binding at all, and Laravel resolves a {@see BackedEnum} through
     * `castBinding()` and a {@see DateTimeInterface} through `prepareBindings()`.
     *
     * @param class-string<Model>|string $bModelClass B's model class ('' for a
     *   capability schema, which validation already forbids from being row-bound).
     * @return array{0: mixed, 1: ?Model} The value to bind, and the model to
     *   thread into B's compile (null unless a hydrated one was supplied).
     */
    private function resolveBoundRow(mixed $value, string $bSchemaKey, string $bModelClass): array
    {
        if ($value instanceof Model) {
            if ($bModelClass === '' || ! $value instanceof $bModelClass) {
                throw new InvalidArgumentException(sprintf(
                    'The row selector for schema [%s] is a [%s], which is not that schema\'s model [%s]; '
                        .'pass that schema\'s own model or a row key.',
                    $bSchemaKey,
                    $value::class,
                    $bModelClass === '' ? 'none' : $bModelClass,
                ));
            }

            /* Hydrated only, as at the guard: an unsaved or deleted instance
               still names a key, but proves nothing about the row being there,
               so it must not let a condition answer from memory. */
            return [$value->getKey(), $value->exists ? $value : null];
        }

        if (
            is_object($value)
            && ! $value instanceof Expression
            && ! $value instanceof BackedEnum
            && ! $value instanceof DateTimeInterface
        ) {
            throw new InvalidArgumentException(sprintf(
                'The row selector for schema [%s] is a [%s], which cannot identify a row; '
                    .'pass a key, that schema\'s model, or a @column/@sql reference.',
                $bSchemaKey,
                $value::class,
            ));
        }

        return [$value, null];
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
    private function crossSchemaCanLeaf(CrossSchemaCanNode $node, CompilationContext $ctx): CompiledWhereClauseNode
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
            $bContext[$key] = $this->resolveArgValue($ctx->queries, $value, $ctx->checkContext);
        }

        $bRuleSet = $this->manager->forSchema($bClass, $ctx->user)->resolvedRuleSet();
        $bCompiler = new self($bSchema, $this->manager);

        /* B compiles off the same factory as A: it is the same connection and the
           same grammar, and the `from` that distinguishes B's subquery is not
           something a factory exposes anyway. */
        $bInput = CompilationInput::descending(
            $ctx->queries,
            $ctx->user,
            new AbilityUnit($node->ability, $bRuleSet),
            $bContext,
            $ctx->visited,
        );

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($ctx->queries, $bModel, $node->schemaKey);

            [$rowId, $bTargetModel] = $this->resolveBoundRow(
                $this->resolveArgValue($ctx->queries, $node->boundRow, $ctx->checkContext),
                $node->schemaKey,
                $bClass::model,
            );

            $bSubquery = $ctx->queries->newQuery()
                ->from($bModel->getTable())
                ->where($bModel->getQualifiedKeyName(), '=', $rowId);

            /* A's model never crosses the boundary — A's row is not B's row — but
               the *selector* may itself have been B's row, in which case B compiles
               against it and B's row conditions can answer in PHP. */
            $bResult = $bCompiler->compile($bInput->forTargetRow($bTargetModel));
            $bDecision = $bResult->decision();

            /* A folded B, with B's row already known to be there, leaves the
               subquery nothing to ask: the exists only ever meant "does that row
               exist, and does B grant it?", and a hydrated model settled the first
               half before we started. Without a model the constant still has to go
               to SQL, because existence is exactly what has not been established. */
            if ($bTargetModel !== null && $bDecision !== null) {
                return (new CompiledWhereClauseNode)->addAnd($bDecision, negated: $ctx->negate);
            }

            $bResult->spliceInto($bSubquery);

            // The exists goes on a leaf of its own, already carrying its negation,
            // so it is a one-clause leaf the tree lifts back out without adding a
            // group — `exists (…)` / `not exists (…)`, as before.
            $existsLeaf = $ctx->queries->newQuery();
            $existsLeaf->addWhereExistsQuery($bSubquery, 'and', $ctx->negate);

            return (new CompiledWhereClauseNode)->addAnd($existsLeaf);
        }

        // Unbound / no-target: row conditions in B are forced false; the result is
        // a correlation-free boolean tree spliced inline (negation-aware), so a B
        // that decides outright folds into A instead of stopping at a `1 = 0`.
        return (new CompiledWhereClauseNode)->addAnd(
            $bCompiler->compile($bInput)->node(),
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
    private function crossSchemaCheckLeaf(CrossSchemaConditionNode $node, CompilationContext $ctx): CompiledWhereClauseNode
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
            $bContext[$key] = $this->resolveArgValue($ctx->queries, $value, $ctx->checkContext);
        }

        // Compile the predicate with B's own resolver, so its condition leaves emit
        // B's SQL. A ConditionUnit walks an expression subtree in isolation.
        $bCompiler = new self($bSchema, $this->manager);

        $bInput = CompilationInput::descending(
            $ctx->queries,
            $ctx->user,
            new ConditionUnit($node->predicate),
            $bContext,
            $ctx->visited,
        );

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($ctx->queries, $bModel, $node->schemaKey);

            [$rowId, $bTargetModel] = $this->resolveBoundRow(
                $this->resolveArgValue($ctx->queries, $node->boundRow, $ctx->checkContext),
                $node->schemaKey,
                $bClass::model,
            );

            $bSubquery = $ctx->queries->newQuery()
                ->from($bModel->getTable())
                ->where($bModel->getQualifiedKeyName(), '=', $rowId);

            // As in a row-bound can(...): A's model never crosses into B, but the
            // selector may have been B's own row.
            $bResult = $bCompiler->compile($bInput->forTargetRow($bTargetModel));
            $bDecision = $bResult->decision();

            // See the row-bound can(...) branch for why a model is required here.
            if ($bTargetModel !== null && $bDecision !== null) {
                return (new CompiledWhereClauseNode)->addAnd($bDecision, negated: $ctx->negate);
            }

            $bResult->spliceInto($bSubquery);

            // As in a row-bound can(...): the exists is its own one-clause leaf,
            // already negated, so the tree lifts it back out without a group.
            $existsLeaf = $ctx->queries->newQuery();
            $existsLeaf->addWhereExistsQuery($bSubquery, 'and', $ctx->negate);

            return (new CompiledWhereClauseNode)->addAnd($existsLeaf);
        }

        // Unbound / no-target: row conditions in B are forced false (validation
        // already forbids them here); the result is a correlation-free boolean
        // tree spliced inline (negation-aware).
        return (new CompiledWhereClauseNode)->addAnd(
            $bCompiler->compile($bInput)->node(),
            negated: $ctx->negate,
        );
    }

    /**
     * A cross-schema `can(...)` embeds B's table as a subquery inside A's query,
     * which executes on a single connection. If B lives on a different connection
     * the emitted SQL would silently reference a table that isn't there, so reject
     * it with a clear message instead.
     */
    private function assertSameConnection(QueryFactory $queries, Model $bModel, string $bSchemaKey): void
    {
        $parentConnection = $queries->connectionName();
        $bConnection = $bModel->getConnection()->getName();

        if ($parentConnection !== $bConnection) {
            throw new InvalidArgumentException(sprintf(
                'Cannot compile a reference to schema [%s]: that schema is on database connection [%s] '
                    .'but the query runs on [%s]; a cross-connection reference is not supported.',
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
    private function resolveArgValue(QueryFactory $queries, mixed $value, array $checkContext): mixed
    {
        if ($value instanceof ContextRef) {
            return $checkContext[$value->key] ?? null;
        }

        if ($value instanceof ColumnRef) {
            return $this->resolveColumnRef($queries, $value);
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
    private function resolveColumnRef(QueryFactory $queries, ColumnRef $ref): Expression
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

        return $queries->wrap($model->getTable() . '.' . $ref->column);
    }

    private function conditionLeaf(ConditionNode $node, CompilationContext $ctx): CompiledWhereClauseNode
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
            $parameters[] = $this->resolveArgValue($ctx->queries, $parameter, $ctx->checkContext);
        }

        $conditionQuery = $ctx->queries->newQuery();

        $result = $this->conditions->applyCondition(
            $node->conditionKey,
            $ctx->user,
            $conditionQuery,
            $ctx->targetSqlId,
            $parameters,
            $ctx->checkContext,
            $ctx->targetModel,
        );

        /* A condition may decide the outcome outright rather than constrain the
           query: a global one evaluated in PHP, or a row one handed the very row
           it is judging. Either way the literal folds into the tree around it. */
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
