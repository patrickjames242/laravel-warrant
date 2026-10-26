<?php

namespace Warrant\DSL\Expanding\Concerns;

use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
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
     */
    private function expandCheckPredicate(CrossSchemaConditionNode $node, ExpansionTrail $trail): CrossSchemaConditionNode
    {
        /* A schema nobody registered has no conditions to expand. Leaving the
           reference as it is lets the validator and the compiler reject it with
           the message they give for any unknown schema. */
        $target = $this->schemaNamed($node->schemaKey->name);

        if ($target === null) {
            return $node;
        }

        /* The `for` names the schema the predicate's names are written for,
           whatever text the check sits in. */
        $predicate = $this->expandExpression(
            $node->predicate,
            $target,
            $node->schemaKey->name,
            new KeyedSchemaId($node->schemaKey->name),
            $trail,
        );

        return $predicate === $node->predicate ? $node : new CrossSchemaConditionNode(
            $node->schemaKey,
            $predicate,
            $node->isRowBound,
            $node->boundKey,
            $node->contextMap,
            $node->alias,
            $node->location,
        );
    }
}
