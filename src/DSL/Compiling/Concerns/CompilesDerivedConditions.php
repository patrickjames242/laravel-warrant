<?php

namespace Warrant\DSL\Compiling\Concerns;

use Warrant\DSL\Compiling\AliasScope;
use Warrant\DSL\Compiling\CallStack\Call;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\Schema\Types\Interfaces\IRowSchema;

/**
 * How {@see RuleSetCompiler} compiles a derived condition's expression, under a
 * scope of its own.
 *
 * @internal
 */
trait CompilesDerivedConditions
{
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
}
