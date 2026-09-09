<?php

namespace Warrant\DSL\Compiling;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\Builders\WarrantConditionBuilder;
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
 * One way in — {@see compile()} — taking a {@see CompilationContext} and returning
 * a {@see CompilationResult}. The context names what to compile (a
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
 * Whether a row is in scope is the caller's to say, via
 * {@see CompilationContext::forTargetRow()} — it is the half they genuinely know and
 * the compiler cannot, since a predicate is detached and where it is eventually
 * spliced is the caller's business. {@see compile()} narrows that answer against
 * {@see ConditionResolver::modelClass()} on the way in, so the walk reads a single
 * already-correct flag. What the row is *called* in SQL is settled here too, and
 * unlike the flag it is nobody else's to know: {@see compile()} seeds an
 * {@see AliasScope} from the schema and the host query, and each cross-schema hop
 * rebinds it, so the same rule text compiles against whichever table its frame
 * actually selects.
 *
 * A condition answers in one of three ways: with a bool it decides outright, with
 * an expression (or the builder that composes one) it *derives* itself from other
 * conditions and the compiler walks the result as if the author had written it in
 * the rule, and otherwise it constrains the builder it was handed. Every condition
 * leaf of that last kind is applied inline as a nested where-group and negated
 * inline (`not (…)`, which for an author's `whereExists` is `not exists (…)`).
 * There is no EXISTS wrapping and no attempt to normalize SQL's three-valued
 * (NULL) logic: a condition compiles to exactly the SQL it emits, so an unknown
 * (NULL) row contributes no access — it never grants and never lifts a deny (the
 * safe direction; the worst case is a legitimate user blocked, never unauthorized
 * access). The compiler holds itself to the same rule for the questions *it*
 * cannot answer — a row condition with no row, a `@column` about a table this
 * frame never selected, a row selector that resolved to nothing — each of which
 * compiles to the third truth value, which negates to itself and so neither
 * grants nor lifts a deny. See {@see Decision} and
 * {@see \Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode}. Because a leaf must be a spliceable boolean, a condition may only add
 * where clauses to its builder; one that emits a join/group/having/aggregate/union
 * is rejected (see {@see conditionLeaf}) — relational checks use
 * `whereExists()`/`whereNotExists()` with a correlated subquery.
 */
final class RuleSetCompiler
{
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
     * The single way in. Which of the three units the context carries decides how
     * the rules are applied; everything else — the query factory the leaves are
     * built from, the user, whether a row is in scope, the check-time context —
     * is the same in all three cases and is read off the context unchanged.
     *
     * The one thing not taken at face value is the target: a capability schema
     * has no model and therefore no row, so a compile against one is never
     * targeted however the caller asked for it. That is the same conclusion
     * validation reaches, settled here, on the way in, so that everything below
     * reads one already-correct flag — and so a row condition folds to `false`
     * rather than emitting a reference to a table that does not exist. Each
     * cross-schema descent re-enters through a compiler bound to that schema, so
     * the referenced schema is narrowed against its own model too.
     */
    public function compile(CompilationContext $ctx): CompilationResult
    {
        if ($ctx->targeted && $this->conditions::modelClass() === '') {
            $ctx = $ctx->withoutTarget();
        }

        if ($ctx->aliases === null) {
            $ctx = $ctx->withAliases($this->rootAliases($ctx));
        }

        $unit = $ctx->unit;

        $node = match (true) {
            $unit instanceof GateUnit => $this->gateNode($ctx, $unit),
            $unit instanceof AbilityUnit => $this->abilityNode($ctx, $unit),
            $unit instanceof ConditionUnit => $this->conditionNode($ctx, $unit),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported compilation unit [%s].', $unit::class),
            ),
        };

        return new CompilationResult($node, $ctx->queries);
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
        $ctx = $ctx->entering(Call::ability($this->conditions::class, $ability));

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

        $grantCtx = $ctx;

        // Grant side: OR of every can-expression (null => always-true term).
        $grantGroup = new CompiledWhereClauseNode;

        foreach ($grants as $grantExpression) {
            $grantGroup->addOr(
                $grantExpression === null ? true : $this->expression($grantExpression, $grantCtx),
            );
        }

        $abilityNode->addAnd($grantGroup);

        /* Deny side: AND NOT(expression) for each conditional `cannot`. A unit is
           always entered unnegated, so toggling here is setting. */
        $denyCtx = $ctx->negated();

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
    private function conditionNode(CompilationContext $ctx, ConditionUnit $unit): CompiledWhereClauseNode
    {
        $conditionNode = new CompiledWhereClauseNode;

        if ($unit->condition === null) {
            return $conditionNode->addAnd(true);
        }

        return $conditionNode->addAnd(
            $this->expression($unit->condition, $ctx),
        );
    }

    /**
     * @param array<int, string> $abilities
     */
    private function listsAbility(array $abilities, string $ability): bool
    {
        return in_array($ability, $abilities, true) || in_array('*', $abilities, true);
    }

    /**
     * Assert the schema being compiled declares $ability.
     *
     * The compiler's own guard against a name that resolves to nothing, held
     * separately from validation because the two see different things: validation
     * reads the rule text before a compile, and cannot see an ability a condition
     * names by deriving itself into a `can(...)` at compile time. Both paths reach
     * here.
     *
     * A rule may still grant an ability with `*`, which is why this asks the schema
     * rather than the rule set — a wildcard grant would otherwise make any
     * misspelling look granted.
     */
    private function assertAbilityDeclared(string $ability): void
    {
        if ($this->conditions->getAbilityDefinition($ability) !== null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Ability [%s] is not declared by schema [%s].',
            $ability,
            $this->conditions::schemaKey(),
        ));
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
            return $node->schemaKey === null
                ? $this->ownAbilityLeaf($node, $ctx)
                : $this->crossSchemaCanLeaf($node, $ctx);
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
     * The scope a top-level compile starts from: the schema's own key, standing
     * for the rows the caller's query is selecting.
     *
     * The name is the key, because that is the only name the rules being compiled
     * can possibly use — their author cannot see the query they will be spliced
     * into. What it stands for is that query's own `from`, so a caller who wrote
     * `from('docs as d')` gets predicates about `d`, falling back to the model's
     * table when the query has no readable `from`.
     *
     * With no row in scope the key is still bound, to nothing: a compile with no
     * target has rows to talk *about* but no `from` to talk about them *in*, and a
     * reference to them folds away rather than erroring like an unknown name would.
     * A schema with no model at all binds nothing — see {@see AliasScope::none()}.
     */
    private function rootAliases(CompilationContext $ctx): AliasScope
    {
        $modelClass = $this->conditions::modelClass();

        if ($modelClass === '') {
            return AliasScope::none();
        }

        return AliasScope::root(
            $this->conditions::schemaKey(),
            $ctx->targeted ? $this->rowQualifierFor($modelClass, $ctx->queries) : null,
        );
    }

    /**
     * The SQL name a schema's rows answer to, or null for a schema with no model
     * and therefore no table at all.
     *
     * $queries is consulted only for the frame the host query itself selects; a
     * cross-schema hop builds its own `from` and passes none.
     */
    private function rowQualifierFor(string $modelClass, ?QueryFactory $queries = null): ?string
    {
        if ($modelClass === '') {
            return null;
        }

        /** @var Model $model */
        $model = new $modelClass;

        return $queries?->rowQualifier() ?? $model->getTable();
    }

    /**
     * The frame's alias scope. {@see compile()} settles it on the way in, so the
     * walk always has one; falling back to the empty scope means a leaf built
     * outside a compile reports "nothing in scope" rather than failing on a null.
     */
    private function aliases(CompilationContext $ctx): AliasScope
    {
        return $ctx->aliases ?? AliasScope::none();
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
     * Compile a `can(<ability>)` that names no schema by compiling that ability of
     * *this* schema into the frame the reference sits in.
     *
     * There is no boundary here, so there is nothing for a subquery to do. The
     * row is the one the enclosing rule is already about and the context is the
     * one it was already given, so an `exists` over this same table matched on its
     * own key would ask a question already answered. The ability's predicate is
     * spliced inline instead, under whatever negation the reference sits under.
     *
     * Recursion is bounded exactly as it is for a hop: {@see abilityNode} enters a
     * {@see Call}, so an ability that names itself is a cycle and everything else
     * spends the depth budget.
     */
    private function ownAbilityLeaf(CrossSchemaCanNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        if ($this->manager === null) {
            throw new InvalidArgumentException(sprintf(
                'Compiling a can(%s) reference requires this schema\'s rule set, which is resolved through '
                    .'the manager; construct RuleSetCompiler with a WarrantManager.',
                $node->ability,
            ));
        }

        $ruleSet = $this->manager->forSchema($this->conditions::class, $ctx->user)->resolvedRuleSet();

        /* A unit is always entered unnegated — {@see abilityNode} sets the deny
           side's polarity itself — so the reference's own negation rides on the
           operand rather than on the context it compiles under. */
        $unnegated = $ctx->negate ? $ctx->negated() : $ctx;

        return (new CompiledWhereClauseNode)->addAnd(
            $this->abilityNode($unnegated, new AbilityUnit($node->ability, $ruleSet)),
            negated: $ctx->negate,
        );
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

        /* Before anything is resolved, because a malformed handle stays malformed
           whatever its arguments turn out to be — and the folds below would
           otherwise answer the reference before it was ever looked at. */
        $this->assertHandleIsWellFormed($node, $bClass::model);

        /* Explicit boundary context only: resolve each with-map RHS against A's
           context and A's frame, with no ambient inheritance of A's bag. A value
           A cannot resolve here settles the whole reference. */
        $bValues = $this->resolveArgValues(array_values($node->contextMap), $ctx);

        if ($bValues === null) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        $bContext = array_combine(array_keys($node->contextMap), $bValues);

        $bRuleSet = $this->manager->forSchema($bClass, $ctx->user)->resolvedRuleSet();
        $bCompiler = new self($bSchema, $this->manager);

        $bQualifier = $this->hopQualifier($node, $bClass::model, $this->aliases($ctx));

        /* B compiles off the same factory as A: it is the same connection and the
           same grammar, and the `from` that distinguishes B's subquery is not
           something a factory exposes anyway. It starts untargeted — A's row is
           never B's row, so the row-bound branch chains forTargetRow() below — and
           unnegated, since A's negation applies to the spliced result rather than
           crossing into B. No call is entered here either: B's own abilityNode()
           enters one, and that is the call a cycle must be detected against.
           The alias scope is passed rather than left to B's compile() to seed,
           because the qualifier is this subquery's `from` and only this frame
           knows it — and it is a *fresh* scope: B's rules were written without
           knowing who reached them, so A's names are deliberately not in it. */
        $bCtx = new CompilationContext(
            unit: new AbilityUnit($node->ability, $bRuleSet),
            queries: $ctx->queries,
            user: $ctx->user,
            checkContext: $bContext,
            callStack: $ctx->callStack,
            /* B's rules name their own key, so that is the name this frame's
               identifier binds to — B's author cannot know what this caller chose
               to call it. A B with no table has no frame for its key to name. */
            aliases: $bClass::model === ''
                ? $this->aliases($ctx)->enteringRowlessRuleSet()
                : $this->aliases($ctx)->enteringRuleSet($node->schemaKey, $bQualifier),
        );

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($ctx->queries, $bModel, $node->schemaKey);

            $selector = $this->resolveArgValues([$node->boundRow], $ctx);

            /* The selector names B's row in terms of A's, so a selector A cannot
               resolve here leaves nothing to correlate against. */
            if ($selector === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

            [$rowId, $bTargetModel] = $this->resolveBoundRow(
                $selector[0],
                $node->schemaKey,
                $bClass::model,
            );

            /* A selector that resolved to nothing — an absent `@context`, or a
               model with no key yet — does not name a row, so this reference is
               unanswerable. It resolves here, before the subquery is built,
               because `exists` is never unknown: being a row-count question, a
               subquery can only report a definite answer about a row nobody
               named. Validation rejects a *literal* null selector; a `@context`
               one is filled per check, so this is the point at which it is known. */
            if ($rowId === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

            $bSubquery = $ctx->queries->newQuery()
                ->from($this->hopFrom($bModel->getTable(), $bQualifier))
                ->where($bQualifier . '.' . $bModel->getKeyName(), '=', $rowId);

            /* A's model never crosses the boundary — A's row is not B's row — but
               the *selector* may itself have been B's row, in which case B compiles
               against it and B's row conditions can answer in PHP. */
            $bResult = $bCompiler->compile($bCtx->forTargetRow($bTargetModel));
            $bDecision = $bResult->decision();

            /* A folded B, with B's row already known to be there, leaves the
               subquery nothing to ask: the exists only ever meant "does that row
               exist, and does B grant it?", and a hydrated model settled the first
               half before we started. Without a model the constant still has to go
               to SQL, because existence is exactly what has not been established. */
            if ($bTargetModel !== null && $bDecision->isConstant()) {
                return (new CompiledWhereClauseNode)->addAnd($bDecision->asOperand(), negated: $ctx->negate);
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
            $bCompiler->compile($bCtx)->node(),
            negated: $ctx->negate,
        );
    }

    /**
     * Compile a cross-schema `check(<predicate> for <schema>[(<row>)] [with <map>])`
     * by dispatching the target schema B's conditions and splicing the emitted SQL.
     * The dispatch itself enters no ability, so this leaf looks for no cycle of its
     * own. It does enter a {@see Call}, because the predicate can reach further —
     * a condition that expands into another expression, a nested `check(...)`, a
     * `can(...)` whose rules are compiled — and those chains are bounded by the
     * depth budget, with a `can(...)` among them caught by the ability cycle guard
     * wherever it is reached from. A
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

        /* Before anything is resolved, because a malformed handle stays malformed
           whatever its arguments turn out to be — and the folds below would
           otherwise answer the reference before it was ever looked at. */
        $this->assertHandleIsWellFormed($node, $bClass::model);

        /* Explicit boundary context only: resolve each with-map RHS against A's
           context and A's frame, with no ambient inheritance of A's bag. A value
           A cannot resolve here settles the whole reference. */
        $bValues = $this->resolveArgValues(array_values($node->contextMap), $ctx);

        if ($bValues === null) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        $bContext = array_combine(array_keys($node->contextMap), $bValues);

        // Compile the predicate with B's own resolver, so its condition leaves emit
        // B's SQL. A ConditionUnit walks an expression subtree in isolation.
        $bCompiler = new self($bSchema, $this->manager);

        $bQualifier = $this->hopQualifier($node, $bClass::model, $this->aliases($ctx));

        /* As in a can(...): B starts untargeted and unnegated. Unlike a can(...),
           the check is entered here, because nothing below will — this compiles B's
           conditions, never B's rules. The alias scope differs from a can(...) too:
           the predicate is written inline in A's own rule text, so A's names stay
           in scope for it to correlate back to, and B's frame is added on top. */
        $bCtx = new CompilationContext(
            unit: new ConditionUnit($node->predicate),
            queries: $ctx->queries,
            user: $ctx->user,
            checkContext: $bContext,
            callStack: $ctx->callStack->enter(Call::check($bClass)),
            aliases: $bClass::model === ''
                ? $this->aliases($ctx)->enteringRowlessPredicate()
                : $this->aliases($ctx)->enteringPredicate($node->schemaKey, $node->alias, $bQualifier),
        );

        if ($node->isRowBound) {
            /** @var Model $bModel */
            $bModel = new ($bClass::model);
            $this->assertSameConnection($ctx->queries, $bModel, $node->schemaKey);

            $selector = $this->resolveArgValues([$node->boundRow], $ctx);

            /* The selector names B's row in terms of A's, so a selector A cannot
               resolve here leaves nothing to correlate against. */
            if ($selector === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

            [$rowId, $bTargetModel] = $this->resolveBoundRow(
                $selector[0],
                $node->schemaKey,
                $bClass::model,
            );

            /* A selector that resolved to nothing — an absent `@context`, or a
               model with no key yet — does not name a row, so this reference is
               unanswerable. It resolves here, before the subquery is built,
               because `exists` is never unknown: being a row-count question, a
               subquery can only report a definite answer about a row nobody
               named. Validation rejects a *literal* null selector; a `@context`
               one is filled per check, so this is the point at which it is known. */
            if ($rowId === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

            $bSubquery = $ctx->queries->newQuery()
                ->from($this->hopFrom($bModel->getTable(), $bQualifier))
                ->where($bQualifier . '.' . $bModel->getKeyName(), '=', $rowId);

            // As in a row-bound can(...): A's model never crosses into B, but the
            // selector may have been B's own row.
            $bResult = $bCompiler->compile($bCtx->forTargetRow($bTargetModel));
            $bDecision = $bResult->decision();

            // See the row-bound can(...) branch for why a model is required here.
            if ($bTargetModel !== null && $bDecision->isConstant()) {
                return (new CompiledWhereClauseNode)->addAnd($bDecision->asOperand(), negated: $ctx->negate);
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
            $bCompiler->compile($bCtx)->node(),
            negated: $ctx->negate,
        );
    }

    /**
     * A hop's `from`: the target's table, aliased when its rows carry a different
     * identifier. The counterpart of {@see hopQualifier()} — the two must agree,
     * or the predicate would name a table the subquery never selected.
     */
    private function hopFrom(string $table, string $qualifier): string
    {
        return $qualifier === $table ? $table : $table . ' as ' . $qualifier;
    }

    /**
     * The identifier a hop's rows carry in the emitted SQL, or null when the hop
     * selects no rows to name.
     *
     * The name the reference asked for — its alias, else the target's table —
     * suffixed only when that identifier already stands for a frame in this
     * query, which a hop into a table already in scope does. Chosen once per hop,
     * because the subquery's `from`, its correlated key and every `@column` that
     * resolves to the frame all have to agree on it.
     */
    private function hopQualifier(
        CrossSchemaCanNode|CrossSchemaConditionNode $node,
        string $modelClass,
        AliasScope $scope,
    ): ?string {
        $table = $this->rowQualifierFor($modelClass);

        if (! $node->isRowBound || $table === null) {
            return null;
        }

        return $scope->freeQualifier($node->alias ?? $table);
    }

    /**
     * Assert a hop's handle is one the target could answer at all — the three ways
     * a handle can be structurally impossible rather than merely unanswerable.
     *
     * Each is a claim about the handle as written, decidable without resolving a
     * single argument, so none of them can be answered with an unknown the way a
     * missing row or an absent `@context` key is:
     *
     *  - a row-bound hop into a schema with no model, which has no rows to select
     *    and no table to select them from;
     *  - a row-bound hop whose selector is a literal `null`, which names no row
     *    (a `@context` selector is a symbol until compile time, so a null *value*
     *    from one is a different thing, and folds);
     *  - an alias on a handle that selects no rows, leaving the name standing for
     *    nothing.
     *
     * Validation makes the same three checks over rule text. This is the same
     * reasoning applied where a handle a condition built by deriving itself also
     * arrives, which validation never sees.
     */
    private function assertHandleIsWellFormed(
        CrossSchemaCanNode|CrossSchemaConditionNode $node,
        string $modelClass,
    ): void {
        $builtin = $node instanceof CrossSchemaCanNode ? 'can' : 'check';

        if ($node->isRowBound && $modelClass === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference targets a specific row of schema [%s], but [%s] has no model and '
                    .'cannot be row-targeted; drop the row selector.',
                $builtin,
                $node->schemaKey,
                $node->schemaKey,
            ));
        }

        if ($node->isRowBound && $node->boundRow === null) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference to schema [%s] specifies a row target that is null; supply a row id '
                    .'or a @context reference, or drop the row selector.',
                $builtin,
                $node->schemaKey,
            ));
        }

        if (! $node->isRowBound && $node->alias !== null) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference to schema [%s] is aliased [as %s] but selects no row, so the alias '
                    .'names nothing; add a row selector like %s(@context id) as %s, or drop the alias.',
                $builtin,
                $node->schemaKey,
                $node->alias,
                $node->schemaKey,
                $node->alias,
            ));
        }
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
     * {@see ColumnRef} becomes a grammar-wrapped {@see Expression} qualified by the
     * frame it was written in. Any already-concrete value (literals, resolved
     * bindings) passes straight through. Shared by condition parameters and the
     * cross-schema handle row selector / `with` map so all three resolve
     * identically — and all three against the *enclosing* frame, which is where
     * their text was written.
     */
    private function resolveArgValue(mixed $argument, CompilationContext $ctx): mixed
    {
        if ($argument instanceof ContextRef) {
            return $ctx->checkContext[$argument->key] ?? null;
        }

        if ($argument instanceof ColumnRef) {
            return $this->resolveColumnRef($argument, $ctx);
        }

        if ($argument instanceof SqlRef) {
            // Always parenthesize (even if the author already did): a bare
            // `select ...` is then valid as a scalar subquery in a comparison.
            return new Expression('(' . $argument->sql . ')');
        }

        return $argument;
    }

    /**
     * Resolve a `@column [<name>.]<column>` reference to an {@see Expression} of the
     * grammar-wrapped `<qualifier>.<column>` identifier (e.g.
     * `` `timesheets`.`pay_period_id` ``), quoted with the query's own grammar so
     * it is emitted verbatim, never re-wrapped or bound as a value.
     *
     * The qualifier comes from the frame the reference was written in — see
     * {@see AliasScope} — and not from the registry, because the same rule text
     * compiles against a different table each time it is reached through a
     * differently-aliased hop. A name the frame does not bind is an author's
     * mistake and throws; that the *named* table really is in scope in the
     * surrounding SQL is now something the scope guarantees, rather than being left
     * to the author and a SQL error at execution.
     */
    private function resolveColumnRef(ColumnRef $ref, CompilationContext $ctx): Expression
    {
        $qualifier = $this->aliases($ctx)->resolve($ref->alias);

        return $ctx->queries->wrap($qualifier . '.' . $ref->column);
    }

    /**
     * Resolve a whole argument list, or answer null when one of them cannot be
     * resolved *here at all*: a `@column` naming a row this frame has no `from`
     * for. That is not an author's mistake — the same rule works where a row is in
     * scope — so it is not an error either; the leaf holding it simply cannot be
     * evaluated, exactly like a row condition with no row, and its caller folds it
     * to `false`. (A name the frame does not bind at all *is* a mistake, and
     * {@see AliasScope::resolve()} still throws for it.)
     *
     * @param array<int, mixed> $arguments
     * @return array<int, mixed>|null
     */
    private function resolveArgValues(array $arguments, CompilationContext $ctx): ?array
    {
        $resolved = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof ColumnRef && $this->aliases($ctx)->resolve($argument->alias) === null) {
                return null;
            }

            $resolved[] = $this->resolveArgValue($argument, $ctx);
        }

        return $resolved;
    }

    private function conditionLeaf(ConditionNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        /* A row condition cannot be evaluated without a row, so a no-target
           compile answers it with the third truth value: an unknown negates to
           itself, so it neither grants nor lifts a deny. The negation flag is
           deliberately not passed on — it would mean nothing to an unknown. */
        if (! $ctx->targeted && ($this->conditions->getConditionDefinition($node->conditionKey)?->isRow ?? false)) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        // Resolve any symbolic argument placeholder. A @context ref is filled from
        // the check-time context — an absent key (only ever a non-required one;
        // required keys are enforced before compilation) resolves to null and is
        // passed to the condition as that argument's value, leaving the condition
        // to decide what null means (rather than the compiler forcing the whole
        // leaf false), so conditions reading a possibly-absent @context arg must
        // tolerate null. A @column ref is resolved to a grammar-wrapped Expression
        // for the referenced schema's real table column.
        $parameters = $this->resolveArgValues($node->parameters, $ctx);

        /* A @column about a row that is not in scope here leaves the condition
           nothing to ask, the same way the row condition above has nothing to ask
           — and the same answer, for the same reason. */
        if ($parameters === null) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        $conditionQuery = $ctx->queries->newQuery();

        $result = $this->conditions->applyCondition(
            $node->conditionKey,
            $ctx->user,
            $conditionQuery,
            $ctx->targeted,
            $parameters,
            $ctx->checkContext,
            $ctx->targetModel,
            /* What this frame's row is called where the predicate lands; the
               resolver falls back to its model's table when it is null. */
            $this->aliases($ctx)->current,
        );

        /* A condition may decide the outcome outright rather than constrain the
           query: a global one evaluated in PHP, or a row one handed the very row
           it is judging. Either way the literal folds into the tree around it. */
        if (is_bool($result)) {
            return (new CompiledWhereClauseNode)->addAnd($result, negated: $ctx->negate);
        }

        /* Or it may answer with structure instead of SQL — an expression built
           from other conditions, composed with the same builder an author writes
           rules with. The tree compiles as if it had been written inline in the
           rule, negation included: that rides on the context and lands on the
           leaves, so `not <derived condition>` is De Morgan'd like anything else.
           Entering a call first is what bounds it — a condition that expands into
           itself has no base case to reach, since compilation never reads a row —
           and it is what puts the expansion in the trace when something does run
           away. The names it compiles against are its own; see
           {@see expansionAliases}. */
        if ($result instanceof WarrantConditionBuilder || $result instanceof IBooleanExpressionNode) {
            return $this->expression(
                $this->expandedCondition($result, $node->conditionKey),
                $ctx->entering(Call::condition($this->conditions::class, $node->conditionKey, $node->parameters))
                    ->withAliases($this->expansionAliases($ctx)),
            );
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
     * The names a derived condition's expression may use: its own schema's key,
     * standing for the frame the condition was asked about, and nothing else.
     *
     * A condition's body is written by the author of its schema, who cannot see
     * where it is reached from — the same position another schema's rule set is
     * in, and so the same scope a `can(...)` hop derives. Every name a caller's
     * text happens to have in scope goes out of scope here: keeping the caller's
     * names would let `@column <own key>.<column>` resolve to the caller's row
     * whenever the caller reached this condition through a `check(… as p)`, since
     * an aliased hop leaves the schema key bound to the frame it was reached
     * *from*. The expansion asks about the frame it was handed, so that is the
     * only frame it can name.
     *
     * A schema with no model has no frame to bind its key to, and a `@column`
     * naming it is a mistake rather than a row out of scope, so that case binds
     * nothing at all.
     */
    private function expansionAliases(CompilationContext $ctx): AliasScope
    {
        $scope = $this->aliases($ctx);

        return $this->conditions::modelClass() === ''
            ? $scope->enteringRowlessRuleSet()
            : $scope->enteringRuleSet($this->conditions::schemaKey(), $scope->current);
    }

    /**
     * The expression a condition answered with, as a node.
     *
     * A {@see WarrantConditionBuilder} is unwrapped to the tree it composed — the
     * same node type the parser produces, so nothing downstream can tell the two
     * apart. A builder with no terms is the structural twin of a condition that
     * added no where clause: it would mean "match everything", which is almost
     * always a forgotten branch rather than an intent to grant universally.
     */
    private function expandedCondition(
        WarrantConditionBuilder|IBooleanExpressionNode $result,
        string $conditionKey,
    ): IBooleanExpressionNode {
        if ($result instanceof IBooleanExpressionNode) {
            return $result;
        }

        $expression = $result->buildConditions();

        if ($expression === null) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] returned a condition builder with no terms, which would '
                    .'silently match every row; add at least one term, or return true/false to decide '
                    .'the outcome outright.',
                $conditionKey,
                $this->conditions::class,
            ));
        }

        return $expression;
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
