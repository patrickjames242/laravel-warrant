<?php

namespace Warrant\DSL\Compiling;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\Units\AbilityUnit;
use Warrant\DSL\Compiling\Units\ConditionUnit;
use Warrant\DSL\Compiling\Units\GateUnit;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\UnknownNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode;
use Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\ExpandedRule;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Resolution\FoundCondition;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\Facades\Warrant;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Context\ContextPolicy;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;
use Warrant\WarrantManager;

/**
 * Compiles an {@see ExpandedSyntax} into SQL predicates.
 *
 * One way in — {@see compile()} — taking a {@see CompilationContext} and returning
 * a {@see CompilationResult}. The context names what to compile (a
 * {@see \Warrant\DSL\Compiling\Units\ICompilationUnit}: a gate, one ability, or a
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
 * whether the schema has rows at all on the way in, so the walk reads a single
 * already-correct flag. What the row is *called* in SQL is settled here too, and
 * unlike the flag it is nobody else's to know: {@see compile()} seeds an
 * {@see AliasScope} from the schema and the host query, and each cross-schema hop
 * rebinds it, so the same rule text compiles against whichever table its frame
 * actually selects.
 *
 * A row or global condition answers in one of three ways: with a bool it decides
 * outright, with a null it answers unknown, and otherwise it constrains the
 * builder it was handed. A derived condition never reaches a leaf: the expansion
 * phase has already replaced it with its expression, wrapped in a
 * {@see DerivedConditionNode} the compiler walks as if the author had written it
 * in the rule. Every condition leaf of that last kind is applied inline
 * as a nested where-group and negated inline (`not (…)`, which for an author's
 * `whereExists` is `not exists (…)`).
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
     * @param IAbilitySchema $schema The schema whose rules are compiled, and
     *   whose conditions and row matcher are invoked.
     * @param string $schemaKey The key $schema is registered under: the name its
     *   rules use for its own rows, and the one its messages give it.
     * @param WarrantManager|null $manager Provides the schema registry (via
     *   {@see WarrantManager::registry()}), required only to compile a
     *   {@see CrossSchemaCanNode} (resolving the referenced schema); null is fine
     *   for rule sets with no cross-schema references.
     */
    public function __construct(
        private readonly IAbilitySchema $schema,
        private readonly string $schemaKey,
        private readonly ?WarrantManager $manager = null,
    ) {
    }

    /**
     * $ctx with its names resolved from the schema $expression's text was written
     * in: the global whose method answered with the text, or this schema.
     */
    private function writtenIn(CompilationContext $ctx, IBooleanExpressionNode $expression): CompilationContext
    {
        return $ctx->withWrittenIn(Warrant::registry()->constructs()->writtenIn($expression->location->source->producer(), $this->schemaKey));
    }

    /**
     * Compile one unit into a predicate.
     *
     * The single way in. Which of the three units the context carries decides how
     * the rules are applied; everything else — the query factory the leaves are
     * built from, the user, whether a row is in scope, the check-time context —
     * is the same in all three cases and is read off the context unchanged.
     *
     * The one thing not taken at face value is the target: a schema with no rows
     * has no row to target, so a compile against one is never targeted however
     * the caller asked for it. That is the same conclusion
     * validation reaches, settled here, on the way in, so that everything below
     * reads one already-correct flag — and so a row condition answers unknown
     * rather than emitting a reference to a table that does not exist. Each
     * cross-schema descent re-enters through a compiler bound to that schema, so
     * the referenced schema is narrowed against its own rows too.
     */
    public function compile(CompilationContext $ctx): CompilationResult
    {
        if ($ctx->targeted && ! $this->schema instanceof IRowSchema) {
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

        if ($node instanceof DerivedConditionNode) {
            return $this->derivedCondition($node, $ctx);
        }

        /* A derived condition that answered null: the third truth value, which
           negates to itself, so the negation flag is deliberately not passed on. */
        if ($node instanceof UnknownNode) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        /* Only an analysis reads one, in place of a condition the text is missing,
           and the text then has a syntax error that a parse to compile throws. */
        if ($node instanceof MissingExpressionNode) {
            throw new InvalidArgumentException(
                'A rule with a missing condition cannot be compiled; the text it was read from has a syntax error.'
            );
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
     * A schema with no rows binds nothing — see {@see AliasScope::none()}.
     */
    private function rootAliases(CompilationContext $ctx): AliasScope
    {
        if (! $this->schema instanceof IRowSchema) {
            return AliasScope::none();
        }

        return AliasScope::root(
            $this->schemaKey,
            $ctx->targeted ? ($ctx->queries->rowQualifier() ?? $this->schema->rowName()) : null,
        );
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
     * A model is read for its key only when it is the handle's *sole* argument,
     * which is the case the default key describes — one value, the primary key.
     * A key of several parts is the schema's own design, so a model among its
     * arguments is passed through as written and is that key's business.
     *
     * @param array<int, mixed> $values The handle's resolved arguments.
     * @param class-string<Model>|null $bModelClass B's model class, or null when B
     *   is not a model schema.
     * @return array{0: array<int, mixed>, 1: ?Model} The arguments to hand the
     *   key, and the model to thread into B's compile (null unless a hydrated one
     *   was supplied as the sole argument).
     */
    private function resolveKeyArguments(array $values, string $bSchemaKey, ?string $bModelClass): array
    {
        if (count($values) !== 1) {
            foreach ($values as $value) {
                $this->assertKeyArgumentIsBindable($value, $bSchemaKey);
            }

            return [$values, null];
        }

        [$value] = $values;

        if ($value instanceof Model) {
            if ($bModelClass === null || ! $value instanceof $bModelClass) {
                throw new InvalidArgumentException(sprintf(
                    'The row selector for schema [%s] is a [%s], which is not that schema\'s model [%s]; '
                        .'pass that schema\'s own model or a row key.',
                    $bSchemaKey,
                    $value::class,
                    $bModelClass ?? 'none',
                ));
            }

            /* Hydrated only, as at the guard: an unsaved or deleted instance
               still names a key, but proves nothing about the row being there,
               so it must not let a condition answer from memory. */
            return [[$value->getKey()], $value->exists ? $value : null];
        }

        $this->assertKeyArgumentIsBindable($value, $bSchemaKey);

        return [[$value], null];
    }

    /**
     * Reject an argument that could never reach SQL as a value.
     *
     * Three object types pass because they already mean something as a binding:
     * an {@see Expression} is `@column` / `@sql` splicing raw SQL rather than
     * binding at all, and Laravel resolves a {@see BackedEnum} through
     * `castBinding()` and a {@see DateTimeInterface} through `prepareBindings()`.
     * A {@see Model} passes too — as one part of a key of several it is whatever
     * that key makes of it.
     */
    private function assertKeyArgumentIsBindable(mixed $value, string $bSchemaKey): void
    {
        if (
            is_object($value)
            && ! $value instanceof Expression
            && ! $value instanceof BackedEnum
            && ! $value instanceof DateTimeInterface
            && ! $value instanceof Model
        ) {
            throw new InvalidArgumentException(sprintf(
                'The row selector for schema [%s] is a [%s], which cannot identify a row; '
                    .'pass a key, that schema\'s model, or a @column/@sql reference.',
                $bSchemaKey,
                $value::class,
            ));
        }
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
     *
     * A context missing a key the ability or this schema requires leaves the
     * reference unknown.
     */
    private function ownAbilityLeaf(CrossSchemaCanNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        if ($this->manager === null) {
            throw new InvalidArgumentException(sprintf(
                'Compiling a can(%s) reference requires this schema\'s rule set, which is resolved through '
                    .'the manager; construct RuleSetCompiler with a WarrantManager.',
                $node->ability->name,
            ));
        }

        /* A check naming this ability directly would throw for a key it requires;
           a reference is not a check entry point, so it answers unknown instead. */
        if ((new ContextPolicy($this->schema))->contextForReference($ctx->checkContext, $node->ability->name) === null) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        $ruleSet = $this->manager->forSchema($this->schema::class, $ctx->user)->expandedRuleSet();

        /* A unit is always entered unnegated — {@see abilityNode} sets the deny
           side's polarity itself — so the reference's own negation rides on the
           operand rather than on the context it compiles under. */
        $unnegated = $ctx->negate ? $ctx->negated() : $ctx;

        return (new CompiledWhereClauseNode)->addAnd(
            $this->abilityNode($unnegated, new AbilityUnit($node->ability->name, $ruleSet)),
            negated: $ctx->negate,
        );
    }

    /**
     * Compile a cross-schema `can(<ability> for <schema>[(<row>)] [with <map>])`
     * by recursively compiling the referenced schema B's ability and embedding it:
     * a row-bound reference wraps B's per-row predicate as `EXISTS` over B's table;
     * an unbound reference splices B's no-target boolean predicate inline. B's
     * context is its own `defaultContext()` with the `with` map merged over it —
     * never A's ambient context. A context missing a key B's schema or the named
     * ability requires throws, because only the rule text can supply it.
     */
    private function crossSchemaCanLeaf(CrossSchemaCanNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        if ($this->manager === null) {
            throw new InvalidArgumentException(sprintf(
                'Compiling a can(...) reference to schema [%s] requires the schema registry; '
                    .'construct RuleSetCompiler with a WarrantManager.',
                $node->schemaKey->name,
            ));
        }

        $bSchema = $this->hopTarget($node);

        /* Before anything is resolved, because a malformed handle stays malformed
           whatever its arguments turn out to be — and the folds below would
           otherwise answer the reference before it was ever looked at. */
        $this->assertHandleIsWellFormed($node, $bSchema);
        $bKeys = $this->contextMapKeys($node->contextMap);

        /* B's bag is B's own defaultContext() under the with map, each RHS
           resolved against A's context and A's frame. A's bag is never inherited.
           A value A cannot resolve here settles the whole reference. A bag
           missing a key B requires is a mistake in the rule text, and throws. */
        $bValues = $this->resolveArgValues(
            array_map(static fn (WithEntryNode $entry): INode => $entry->value, $node->contextMap),
            $ctx,
        );

        if ($bValues === null) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        $bContext = (new ContextPolicy($bSchema))->resolveBoundaryContext(
            array_combine($bKeys, $bValues),
            $node->schemaKey->name,
            $node->ability->name,
        );

        $bRuleSet = $this->manager->forSchema($bSchema::class, $ctx->user)->expandedRuleSet();
        $bCompiler = new self($bSchema, $node->schemaKey->name, $this->manager);

        $bQualifier = $this->hopQualifier($node, $bSchema, $this->aliases($ctx));

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
            unit: new AbilityUnit($node->ability->name, $bRuleSet),
            queries: $ctx->queries,
            user: $ctx->user,
            checkContext: $bContext,
            callStack: $ctx->callStack,
            /* B's rules name their own key, so that is the name this frame's
               identifier binds to — B's author cannot know what this caller chose
               to call it. A B with no table has no frame for its key to name. */
            aliases: ! $bSchema instanceof IRowSchema
                ? $this->aliases($ctx)->enteringRowlessRuleSet()
                : $this->aliases($ctx)->enteringRuleSet($node->schemaKey->name, $bQualifier),
        );

        if ($node->isRowBound) {
            $this->assertSameConnection($ctx->queries, $bSchema, $node->schemaKey->name);

            $selector = $this->resolveArgValues($node->boundKey, $ctx);

            /* The selector names B's row in terms of A's, so a selector A cannot
               resolve here leaves nothing to correlate against. */
            if ($selector === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

            [$keyArguments, $bTargetModel] = $this->resolveKeyArguments(
                $selector,
                $node->schemaKey->name,
                $bSchema instanceof IModelSchema ? $bSchema::model() : null,
            );

            $bSubquery = $bSchema->fromRows($ctx->queries->newQuery(), $bQualifier);

            /* B says how its own rows are addressed, and the handle's arguments
               are that key's arguments. A key given nothing that names a row
               answers unknown — an absent `@context`, or a model with no key yet
               — and that settles the reference here, before the subquery is
               finished, because `exists` is never unknown: being a row-count
               question, a subquery can only report a definite answer about a row
               nobody named. Validation rejects a *literal* null selector; a
               `@context` one is filled per check, so this is the point at which
               it is known. */
            $keyed = $this->narrowToRow($bSchema, $ctx->user, $bSubquery, $keyArguments, $bContext, $bTargetModel, $bQualifier);

            if ($keyed === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

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

        // Unbound / no-target: with no row in scope, a row condition in B folds to
        // unknown, which neither grants nor lifts a deny. The result is a
        // correlation-free boolean tree spliced inline (negation-aware), so a B
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
     * a derived condition's expression, a nested `check(...)`, a `can(...)` whose
     * rules are compiled — and those chains are bounded by the
     * depth budget, with a `can(...)` among them caught by the ability cycle guard
     * wherever it is reached from. A
     * row-bound reference wraps B's predicate as `EXISTS` over B's table
     * (`NOT EXISTS` when negated); an unbound reference splices B's boolean predicate
     * inline. The predicate's condition leaves are compiled with B's own resolver,
     * and B's context is its own `defaultContext()` with the `with` map merged over
     * it — never A's ambient bag. A context missing a key B's schema requires
     * throws, because only the rule text can supply it.
     */
    private function crossSchemaCheckLeaf(CrossSchemaConditionNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        if ($this->manager === null) {
            throw new InvalidArgumentException(sprintf(
                'Compiling a check(...) reference to schema [%s] requires the schema registry; '
                    .'construct RuleSetCompiler with a WarrantManager.',
                $node->schemaKey->name,
            ));
        }

        $bSchema = $this->hopTarget($node);

        /* Before anything is resolved, because a malformed handle stays malformed
           whatever its arguments turn out to be — and the folds below would
           otherwise answer the reference before it was ever looked at. */
        $this->assertHandleIsWellFormed($node, $bSchema);
        $this->assertPredicateAsksTarget($node->predicate, $node);
        $bKeys = $this->contextMapKeys($node->contextMap);

        /* B's bag is B's own defaultContext() under the with map, each RHS
           resolved against A's context and A's frame. A's bag is never inherited.
           A value A cannot resolve here settles the whole reference. A bag
           missing a key B requires is a mistake in the rule text, and throws. */
        $bValues = $this->resolveArgValues(
            array_map(static fn (WithEntryNode $entry): INode => $entry->value, $node->contextMap),
            $ctx,
        );

        if ($bValues === null) {
            return (new CompiledWhereClauseNode)->addAnd(null);
        }

        $bContext = (new ContextPolicy($bSchema))->resolveBoundaryContext(
            array_combine($bKeys, $bValues),
            $node->schemaKey->name,
        );

        // Compile the predicate with B's own resolver, so its condition leaves emit
        // B's SQL. A ConditionUnit walks an expression subtree in isolation.
        $bCompiler = new self($bSchema, $node->schemaKey->name, $this->manager);

        $bQualifier = $this->hopQualifier($node, $bSchema, $this->aliases($ctx));

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
            callStack: $ctx->callStack->enter(Call::check($bSchema::class)),
            aliases: ! $bSchema instanceof IRowSchema
                ? $this->aliases($ctx)->enteringRowlessPredicate()
                : $this->aliases($ctx)->enteringPredicate($node->schemaKey->name, $node->alias?->name, $bQualifier),
            /* The `for` names the schema the predicate's names are written for,
               whatever text the check sits in. */
            writtenIn: new KeyedSchemaId($node->schemaKey->name),
        );

        if ($node->isRowBound) {
            $this->assertSameConnection($ctx->queries, $bSchema, $node->schemaKey->name);

            $selector = $this->resolveArgValues($node->boundKey, $ctx);

            /* The selector names B's row in terms of A's, so a selector A cannot
               resolve here leaves nothing to correlate against. */
            if ($selector === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

            [$keyArguments, $bTargetModel] = $this->resolveKeyArguments(
                $selector,
                $node->schemaKey->name,
                $bSchema instanceof IModelSchema ? $bSchema::model() : null,
            );

            $bSubquery = $bSchema->fromRows($ctx->queries->newQuery(), $bQualifier);

            /* B says how its own rows are addressed, and the handle's arguments
               are that key's arguments. A key given nothing that names a row
               answers unknown — an absent `@context`, or a model with no key yet
               — and that settles the reference here, before the subquery is
               finished, because `exists` is never unknown: being a row-count
               question, a subquery can only report a definite answer about a row
               nobody named. Validation rejects a *literal* null selector; a
               `@context` one is filled per check, so this is the point at which
               it is known. */
            $keyed = $this->narrowToRow($bSchema, $ctx->user, $bSubquery, $keyArguments, $bContext, $bTargetModel, $bQualifier);

            if ($keyed === null) {
                return (new CompiledWhereClauseNode)->addAnd(null);
            }

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

        // Unbound / no-target: as in a can(...), a row condition in B folds to
        // unknown for want of a row — a predicate may freely mix row and global
        // leaves, so validation judges no leaf on its row-ness. The result is a
        // correlation-free boolean tree spliced inline (negation-aware).
        return (new CompiledWhereClauseNode)->addAnd(
            $bCompiler->compile($bCtx)->node(),
            negated: $ctx->negate,
        );
    }

    /**
     * The schema a hop names, from the registry. A hop reaches its target's
     * abilities or conditions, so the target must be a schema checks are made
     * against.
     */
    private function hopTarget(CrossSchemaCanNode|CrossSchemaConditionNode $node): IAbilitySchema
    {
        $schema = $this->manager->registry()->resolveOrFail($node->schemaKey->name)->schema;

        if (! $schema instanceof IAbilitySchema) {
            throw new InvalidArgumentException(sprintf(
                'A reference to schema [%s] needs a schema checks are made against, but [%s] is not an %s.',
                $node->schemaKey->name,
                $schema::class,
                IAbilitySchema::class,
            ));
        }

        return $schema;
    }

    /**
     * Narrow a hop's subquery to the row $arguments name, through the target's
     * row matcher. Null when the arguments name no row, which answers unknown.
     *
     * The matcher is handed the context a row condition would be, built over the
     * subquery, so it may use a model scope or follow the row's name through
     * {@see RowConditionContext::column()}.
     *
     * @param array<int, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function narrowToRow(
        IRowSchema $schema,
        Authenticatable $user,
        Builder $subquery,
        array $arguments,
        array $context,
        ?Model $targetModel,
        string $rowName,
    ): ?Builder {
        return $schema->invokeRowMatcher(new RowConditionContext(
            $user,
            $schema->conditionQuery($subquery, $rowName),
            $rowName,
            $schema->keyColumns(),
            $arguments,
            $context,
            $targetModel,
        ));
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
        IAbilitySchema $schema,
        AliasScope $scope,
    ): ?string {
        $table = $schema instanceof IRowSchema ? $schema->rowName() : null;

        if (! $node->isRowBound || $table === null) {
            return null;
        }

        return $scope->freeQualifier($node->alias?->name ?? $table);
    }

    /**
     * Assert a hop's handle is one the target could answer at all — the three ways
     * a handle can be structurally impossible rather than merely unanswerable.
     *
     * Each is a claim about the handle as written, decidable without resolving a
     * single argument, so none of them can be answered with an unknown the way a
     * missing row or an absent `@context` key is:
     *
     *  - a row-bound hop into a schema with no rows, which has nothing to select
     *    and no source to select it from;
     *  - a row-bound hop whose selector is a literal `null`, which names no row
     *    (a `@context` selector is a symbol until compile time, so a null *value*
     *    from one is a different thing, and folds);
     *  - an alias on a handle that selects no rows, leaving the name standing for
     *    nothing.
     *
     * Validation makes the same three checks over each text as written. They are
     * made again here for rules that reached the compile without it.
     */
    private function assertHandleIsWellFormed(
        CrossSchemaCanNode|CrossSchemaConditionNode $node,
        IAbilitySchema $schema,
    ): void {
        $builtin = $node instanceof CrossSchemaCanNode ? 'can' : 'check';

        if ($node->isRowBound && ! $schema instanceof IRowSchema) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference targets a specific row of schema [%s], but [%s] has no rows and '
                    .'cannot be row-targeted; drop the row selector.',
                $builtin,
                $node->schemaKey->name,
                $node->schemaKey->name,
            ));
        }

        $literalNulls = array_filter(
            $node->boundKey,
            static fn (INode $argument): bool => $argument instanceof LiteralNode && $argument->value === null,
        );

        if ($node->isRowBound && $literalNulls !== []) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference to schema [%s] specifies a row target that is null; supply a row id '
                    .'or a @context reference, or drop the row selector.',
                $builtin,
                $node->schemaKey->name,
            ));
        }

        if (! $node->isRowBound && $node->alias !== null) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference to schema [%s] is aliased [as %s] but selects no row, so the alias '
                    .'names nothing; add a row selector like %s(@context id) as %s, or drop the alias.',
                $builtin,
                $node->schemaKey->name,
                $node->alias->name,
                $node->schemaKey->name,
                $node->alias->name,
            ));
        }
    }

    /**
     * A `check(...)` predicate that decides itself asks the target nothing, so a
     * constant in it is rejected, with the message validation gives the same
     * mistake in rule text. A derived condition in it may still answer with a
     * constant: that answer is the condition's, not the predicate's.
     */
    private function assertPredicateAsksTarget(IBooleanExpressionNode $predicate, CrossSchemaConditionNode $check): void
    {
        if ($predicate instanceof NotNode) {
            $this->assertPredicateAsksTarget($predicate->operand, $check);
        } elseif ($predicate instanceof AndNode || $predicate instanceof OrNode) {
            $this->assertPredicateAsksTarget($predicate->leftSide, $check);
            $this->assertPredicateAsksTarget($predicate->rightSide, $check);
        } elseif ($predicate instanceof BooleanNode) {
            throw new InvalidArgumentException(sprintf(
                'A check(...) predicate for schema [%s] may not contain a constant; it has to ask that '
                    .'schema something.',
                $check->schemaKey->name,
            ));
        }
    }

    /**
     * The `with` map's keys, in the order written. A key written twice would give
     * the target two values for one name, so it is rejected, with the message
     * validation gives the same mistake in rule text.
     *
     * @param list<WithEntryNode> $contextMap
     * @return list<string>
     */
    private function contextMapKeys(array $contextMap): array
    {
        $keys = [];

        foreach ($contextMap as $entry) {
            if (in_array($entry->key->name, $keys, true)) {
                throw new InvalidArgumentException(sprintf("Duplicate key '%s' in the 'with' map.", $entry->key->name));
            }

            $keys[] = $entry->key->name;
        }

        return $keys;
    }

    /**
     * A cross-schema `can(...)` embeds B's table as a subquery inside A's query,
     * which executes on a single connection. If B lives on a different connection
     * the emitted SQL would silently reference a table that isn't there, so reject
     * it with a clear message instead.
     */
    private function assertSameConnection(QueryFactory $queries, IAbilitySchema $bSchema, string $bSchemaKey): void
    {
        $parentConnection = $queries->connectionName();
        $bConnection = $bSchema instanceof IRowSchema ? $bSchema->connection()->getName() : null;

        if ($bConnection !== null && $parentConnection !== $bConnection) {
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
     * frame it was written in; a {@see SqlRef} an {@see Expression} of its body; a
     * {@see LiteralNode} its value. An expanded rule set holds no binding, so any
     * other node is rejected. Shared by condition parameters and the
     * cross-schema handle row selector / `with` map so all three resolve
     * identically — and all three against the *enclosing* frame, which is where
     * their text was written.
     */
    private function resolveArgValue(INode $argument, CompilationContext $ctx): mixed
    {
        /* Only an analysis reads one, in place of an argument the text is missing,
           and the text then has a syntax error that a parse to compile throws. */
        if ($argument instanceof MissingArgumentNode) {
            throw new InvalidArgumentException(
                'A rule with a missing argument cannot be compiled; the text it was read from has a syntax error.'
            );
        }

        if ($argument instanceof ContextRef) {
            return $ctx->checkContext[$argument->key->name] ?? null;
        }

        if ($argument instanceof ColumnRef) {
            return $this->resolveColumnRef($argument, $ctx);
        }

        if ($argument instanceof SqlRef) {
            // Always parenthesize (even if the author already did): a bare
            // `select ...` is then valid as a scalar subquery in a comparison.
            return new Expression('(' . $this->resolveArgValue($argument->sql, $ctx) . ')');
        }

        if ($argument instanceof LiteralNode) {
            return $argument->value;
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported argument node [%s]; compile a rule set expanded by %s.',
            $argument::class,
            Expander::class,
        ));
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
        $qualifier = $this->aliases($ctx)->resolveColumn($ref);

        return $ctx->queries->wrap($qualifier . '.' . $ref->column->name);
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
     * @param list<INode> $arguments
     * @return array<int, mixed>|null
     */
    private function resolveArgValues(array $arguments, CompilationContext $ctx): ?array
    {
        $resolved = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof ColumnRef && $this->aliases($ctx)->resolveColumn($argument) === null) {
                return null;
            }

            $resolved[] = $this->resolveArgValue($argument, $ctx);
        }

        return $resolved;
    }

    private function conditionLeaf(ConditionNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        $writtenIn = $ctx->writtenIn ?? new KeyedSchemaId($this->schemaKey);
        $found = Warrant::registry()->constructs()->condition($node->conditionKey->name, $writtenIn);
        $definition = $found?->definition;

        if ($found === null || $definition === null) {
            throw new InvalidArgumentException(LanguageRuleMessages::unknownCondition($writtenIn, $node->conditionKey->name));
        }

        /* A derived condition has no SQL of its own; the expansion phase replaces
           it with the expression it answers with. Meeting one here means the tree
           never went through that phase. */
        if ($definition->isDerived()) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] is a derived condition and reached the compiler unexpanded; '
                    .'compile a rule set expanded by %s.',
                $node->conditionKey->name,
                $this->schema::class,
                Expander::class,
            ));
        }

        /* A row condition cannot be evaluated without a row, so a no-target
           compile answers it with the third truth value: an unknown negates to
           itself, so it neither grants nor lifts a deny. The negation flag is
           deliberately not passed on — it would mean nothing to an unknown. */
        if (! $ctx->targeted && $definition->isRow()) {
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

        /* The parameters after a condition's context object are its DSL
           arguments: those without a default are required, and a call may pass no
           more than there are, unless the last is variadic. Validation reports
           both against the rule text; this guards every other way a rule arrives. */
        if (count($parameters) < $definition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.',
                $node->conditionKey->name,
                $found->schemaClass(),
                $definition->requiredArgumentCount,
                count($parameters),
            ));
        }

        if ($definition->maxArgumentCount !== null && count($parameters) > $definition->maxArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] takes at most %d argument(s), but the rule supplied %d.',
                $node->conditionKey->name,
                $found->schemaClass(),
                $definition->maxArgumentCount,
                count($parameters),
            ));
        }

        $conditionQuery = $ctx->queries->newQuery();

        $result = $this->invokeCondition($found, $conditionQuery, $parameters, $ctx);

        /* Or it may answer that the question has no answer here, which is the
           third truth value: an unknown negates to itself, so it neither grants
           nor lifts a deny. The negation flag is deliberately not passed on — it
           would mean nothing to an unknown. */
        if ($result === null) {
            $this->assertUnknownAddedNoWhereClause($conditionQuery, $node->conditionKey->name);

            return (new CompiledWhereClauseNode)->addAnd(null);
        }

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
        $this->assertOnlyWhereClauses($conditionQuery, $node->conditionKey->name);
        $this->assertAddedAWhereClause($conditionQuery, $node->conditionKey->name);

        // The condition's where-group becomes a leaf, applied inline. Negation
        // rides along on the operand and lands as a `not (…)` nested group — the
        // same way Laravel's whereNot composes its boolean — so a scalar leaf
        // follows SQL's three-valued logic and an author's whereExists reads as
        // `not exists (…)`.
        return (new CompiledWhereClauseNode)->addAnd($conditionQuery, negated: $ctx->negate);
    }

    /**
     * Invoke a row or global condition over $conditionQuery, with the context
     * object its kind is handed, on the schema that declares it: this schema, or
     * the global the name resolved to.
     *
     * A row condition is told what its row is called where the predicate lands:
     * this frame's name for it, or the schema's own row name when nothing closer
     * names it. Its schema decides what builder it constrains, which for a model
     * is a wrapper the model's scopes can be used on. Either way its constraints
     * land in $conditionQuery.
     *
     * @param array<int, mixed> $parameters
     */
    private function invokeCondition(
        FoundCondition $found,
        Builder $conditionQuery,
        array $parameters,
        CompilationContext $ctx,
    ): Builder|bool|null {
        $definition = $found->definition;

        if (! $definition->isRow()) {
            return $found->schema()->invokeGlobalCondition(
                $definition,
                new GlobalConditionContext($ctx->user, $conditionQuery, $parameters, $ctx->checkContext),
            );
        }

        /** @var IRowSchema $schema A row condition is only ever declared by a schema with rows. */
        $schema = $this->schema;
        $rowName = $this->aliases($ctx)->current ?? $schema->rowName();

        return $schema->invokeRowCondition($definition, new RowConditionContext(
            $ctx->user,
            $schema->conditionQuery($conditionQuery, $rowName),
            $rowName,
            $schema->keyColumns(),
            $parameters,
            $ctx->checkContext,
            $ctx->targetModel,
        ));
    }

    /**
     * Compile a derived condition's expanded body as if it had been written inline
     * in the rule, negation included: that rides on the context and lands on the
     * leaves, so `not <derived condition>` is De Morgan'd like anything else.
     *
     * Two things set it apart from text written inline. It compiles under the
     * names its own author could see; see {@see expansionAliases}. And it enters a
     * call, so a compile trace shows the layer the rule text cannot — a `can(...)`
     * that closes a cycle from inside a condition, say. The body was bounded when
     * it was expanded, so the call is counted against the depth budget like any
     * other but can no longer recur.
     *
     * The arguments are the exception to the names. They are the caller's text,
     * so a `@column` among them is resolved here, before the body's scope is
     * entered, and keeps that answer wherever the body passes it on.
     */
    private function derivedCondition(DerivedConditionNode $node, CompilationContext $ctx): CompiledWhereClauseNode
    {
        $ctx = $ctx->withAliases($this->aliases($ctx)->forwardingColumns($node->parameters));

        $declaredBy = $node->body->location->source->producer()?->schemaClass ?? $this->schema::class;

        return $this->expression(
            $node->body,
            $this->writtenIn($ctx, $node->body)
                ->entering(Call::condition($declaredBy, $node->conditionKey->name, $node->parameters))
                ->withAliases($this->expansionAliases($ctx)),
        );
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
     * A schema with no rows has no frame to bind its key to, and a `@column`
     * naming it is a mistake rather than a row out of scope, so that case binds
     * nothing at all.
     */
    private function expansionAliases(CompilationContext $ctx): AliasScope
    {
        $scope = $this->aliases($ctx);

        return $this->schema instanceof IRowSchema
            ? $scope->enteringRuleSet($this->schemaKey, $scope->current)
            : $scope->enteringRowlessRuleSet();
    }

    /**
     * A condition that added no where clause emitted nothing at all, which would
     * silently mean "match every row" — almost always an author's forgotten
     * branch rather than an intent to grant everything. A condition that really
     * does decide the outcome should say so by returning a bool, and one that
     * cannot be evaluated at all by returning null.
     */
    private function assertAddedAWhereClause(Builder $conditionQuery, string $conditionKey): void
    {
        if ($conditionQuery->wheres !== []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Condition [%s] on schema [%s] added no where clause; a condition must add at least one '
                .'where clause, return true/false to decide the outcome outright, or return null to '
                .'answer unknown.',
            $conditionKey,
            $this->schema::class,
        ));
    }

    /**
     * A condition answering unknown must leave its builder untouched.
     *
     * PHP returns null from a method with no `return` statement, so a condition
     * that constrained the query and then fell off the end arrives here
     * indistinguishable from one that deliberately answered unknown — and the two
     * mean opposite things. The builder settles it: a where clause was added, so
     * the predicate was the intended answer and the missing return is the bug.
     */
    private function assertUnknownAddedNoWhereClause(Builder $conditionQuery, string $conditionKey): void
    {
        if ($conditionQuery->wheres === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Condition [%s] on schema [%s] returned null, answering unknown, but also added a where '
                .'clause; return the builder it constrained, or answer unknown without constraining it.',
            $conditionKey,
            $this->schema::class,
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
            $this->schema::class,
            $offending,
        ));
    }
}
