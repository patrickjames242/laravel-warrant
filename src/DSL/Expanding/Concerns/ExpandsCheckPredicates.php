<?php

namespace Warrant\DSL\Expanding\Concerns;

use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IHandleNode;
use Warrant\DSL\Parsing\ASTNodes\RelationHandleNode;
use Warrant\DSL\Resolution\KeyedSchemaId;

/**
 * How {@see Expander} expands a `check(...)`: its predicate is read
 * against the schema it names, whose conditions those are.
 *
 * @internal
 */
trait ExpandsCheckPredicates
{
    /**
     * A `check(...)` with its predicate expanded against the schema it names,
     * whose conditions those are.
     *
     * @param string $schemaKey The schema the rule is for, whose row relation an
     *   `its` handle follows.
     */
    private function expandCheckPredicate(CrossSchemaConditionNode $node, string $schemaKey, ExpansionTrail $trail): CrossSchemaConditionNode
    {
        $targetKey = $this->handleTargetKey($node->handle, $schemaKey);

        /* A schema nobody registered, or a relation nothing declares, has no
           conditions to expand. Leaving the reference as it is lets the validator
           and the compiler reject it with the message they give for either. */
        $target = $targetKey === null ? null : $this->schemaNamed($targetKey);

        if ($target === null) {
            return $node;
        }

        /* The `for` names the schema the predicate's names are written for,
           whatever text the check sits in. */
        $predicate = $this->expandExpression(
            $node->predicate,
            $target,
            $targetKey,
            new KeyedSchemaId($targetKey),
            $trail,
        );

        return $predicate === $node->predicate ? $node : new CrossSchemaConditionNode(
            $node->handle,
            $predicate,
            $node->contextMap,
            $node->alias,
            $node->location,
        );
    }

    /**
     * The key of the schema $handle reaches: the one written after `for`, or the
     * target of the row relation `its` follows. Null for a relation nothing
     * declares.
     *
     * @param string $schemaKey The schema the rule is for, whose row relation an
     *   `its` handle follows.
     */
    private function handleTargetKey(IHandleNode $handle, string $schemaKey): ?string
    {
        return $handle instanceof RelationHandleNode
            ? $this->catalog->constructs()->rowRelation($handle->relation->name, $schemaKey)?->targetKey
            : $handle->schemaKey->name;
    }
}
