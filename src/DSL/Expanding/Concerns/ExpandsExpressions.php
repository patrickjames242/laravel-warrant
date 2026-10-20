<?php

namespace Warrant\DSL\Expanding\Concerns;

use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\SchemaVocabulary;

/**
 * How {@see Expander} walks a boolean expression to the derived
 * conditions and `check(...)` predicates in it, rebuilding only what changes.
 *
 * @internal
 */
trait ExpandsExpressions
{
    /**
     * $node with every derived condition in it expanded, the names read against
     * $schema. A subtree with nothing to expand comes back as the same instance.
     */
    private function expandExpression(
        IBooleanExpressionNode $node,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): IBooleanExpressionNode {
        if ($node instanceof AndNode || $node instanceof OrNode) {
            $left = $this->expandExpression($node->leftSide, $schema, $schemaKey, $trail);
            $right = $this->expandExpression($node->rightSide, $schema, $schemaKey, $trail);

            return $left === $node->leftSide && $right === $node->rightSide
                ? $node
                : new ($node::class)($left, $right, $node->location);
        }

        if ($node instanceof NotNode) {
            $operand = $this->expandExpression($node->operand, $schema, $schemaKey, $trail);

            return $operand === $node->operand ? $node : new NotNode($operand, $node->location);
        }

        if ($node instanceof CrossSchemaConditionNode) {
            return $this->expandCheckPredicate($node, $trail);
        }

        if ($node instanceof ConditionNode) {
            $definition = $schema->getConditionDefinition($node->conditionKey->name);

            /* A name the schema does not declare is left for validation and the
               compiler to report, in the words they use for any other. */
            return $definition !== null && $definition->isDerived()
                ? $this->expandDerivedCondition($node, $definition, $schema, $schemaKey, $trail)
                : $node;
        }

        return $node;
    }
}
