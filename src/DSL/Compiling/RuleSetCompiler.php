<?php

namespace Warrant\DSL\Compiling;

use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\Concerns\CompilesAbilityReferences;
use Warrant\DSL\Compiling\Concerns\CompilesCheckReferences;
use Warrant\DSL\Compiling\Concerns\CompilesConditionLeaves;
use Warrant\DSL\Compiling\Concerns\CompilesDerivedConditions;
use Warrant\DSL\Compiling\Concerns\CompilesUnits;
use Warrant\DSL\Compiling\Concerns\ResolvesArguments;
use Warrant\DSL\Compiling\Concerns\ResolvesHopTargets;
use Warrant\DSL\Compiling\Units\AbilityUnit;
use Warrant\DSL\Compiling\Units\ConditionUnit;
use Warrant\DSL\Compiling\Units\GateUnit;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Expanding\UnknownNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\Facades\Warrant;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
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
    use CompilesUnits;
    use CompilesAbilityReferences;
    use CompilesCheckReferences;
    use ResolvesHopTargets;
    use ResolvesArguments;
    use CompilesConditionLeaves;
    use CompilesDerivedConditions;

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
            return $node->handle === null
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
}
