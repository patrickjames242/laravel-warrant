<?php

namespace Warrant\DSL\Compiling\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Resolution\FoundCondition;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\Facades\Warrant;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Types\Interfaces\IRowSchema;

/**
 * How {@see RuleSetCompiler} compiles a row or global condition: it calls the
 * condition's method and holds what it emitted to where clauses only.
 *
 * @internal
 */
trait CompilesConditionLeaves
{
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
        $this->assertOnlyWhereClauses($conditionQuery, sprintf('Condition [%s]', $node->conditionKey->name));
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
     * not on this builder, so they are allowed). A row relation's method is held
     * to the same rule, since its clauses land in the hop's subquery.
     *
     * @param string $label What added the clauses, for the message:
     *   `Condition [is_owner]` or `Row relation [folder]`.
     */
    private function assertOnlyWhereClauses(Builder $conditionQuery, string $label): void
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
            '%s on schema [%s] may only add where clauses, but it emitted a [%s]; '
                .'use whereExists()/whereNotExists() with a correlated subquery instead of join()/groupBy()/having().',
            $label,
            $this->schema::class,
            $offending,
        ));
    }
}
