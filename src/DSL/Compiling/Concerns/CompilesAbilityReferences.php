<?php

namespace Warrant\DSL\Compiling\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Compiling\CallStack\Call;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Compiling\Units\AbilityUnit;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\Schema\Context\ContextPolicy;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;
use Warrant\WarrantManager;

/**
 * How {@see RuleSetCompiler} compiles a `can(...)`: one that names no schema
 * compiles that ability of the schema in scope, and one with a `for` compiles
 * the target schema's rules for that ability.
 *
 * @internal
 */
trait CompilesAbilityReferences
{
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
}
