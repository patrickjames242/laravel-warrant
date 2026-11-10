<?php

namespace Warrant\DSL\Compiling\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Compiling\CallStack\Call;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Compiling\Units\ConditionUnit;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\Schema\Context\ContextPolicy;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;
use Warrant\WarrantManager;

/**
 * How {@see RuleSetCompiler} compiles a `check(...)`: the predicate is compiled
 * with the target schema's conditions, and the target's rules are never read.
 *
 * @internal
 */
trait CompilesCheckReferences
{
    /**
     * Compile a cross-schema `check(<predicate> for <schema>[(<row>)] [with <map>])`
     * by dispatching the target schema B's conditions and splicing the emitted SQL.
     * The dispatch itself enters no ability, so this leaf looks for no cycle of its
     * own. It does enter a {@see Call}, because the predicate can reach further —
     * a derived condition's expression, or a `can(...)` whose rules are compiled
     * — and those chains are bounded by the
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
     * A `check(...)` predicate that decides itself asks the target nothing, so a
     * constant in it is rejected, with the message validation gives the same
     * mistake in rule text. A derived condition in it may still answer with a
     * constant: that answer is the condition's, not the predicate's.
     *
     * A `check(...)` in it is rejected too, and so is a `can(...)` with a `for`.
     * A predicate sees two frames at most, the rule's own and the one its handle
     * names, and asks only the schema its handle names. A derived condition in it
     * may still hold either, because its text starts a scope of its own.
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
        } elseif ($predicate instanceof CrossSchemaConditionNode) {
            throw new InvalidArgumentException(sprintf(
                'A check(...) may not be written inside the predicate of the check(...) for schema [%s]; '
                    .'ask it from a derived condition of schema [%s] instead.',
                $check->schemaKey->name,
                $check->schemaKey->name,
            ));
        } elseif ($predicate instanceof CrossSchemaCanNode && $predicate->schemaKey !== null) {
            throw new InvalidArgumentException(sprintf(
                'A can(...) inside the predicate of the check(...) for schema [%s] may not name a schema with for; '
                    .'write can(<ability>) to ask schema [%s] about one of its own abilities.',
                $check->schemaKey->name,
                $check->schemaKey->name,
            ));
        }
    }
}
