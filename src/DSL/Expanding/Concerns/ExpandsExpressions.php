<?php

namespace Warrant\DSL\Expanding\Concerns;

use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * How {@see Expander} walks a boolean expression to the derived
 * conditions and `check(...)` predicates in it, rebuilding only what changes.
 *
 * @internal
 */
trait ExpandsExpressions
{
    /**
     * $node with every derived condition in it expanded, its names resolved from
     * $writtenIn, the schema its text was written in. $schema is the schema
     * whose rules the expression is in. A subtree with nothing to expand comes back as the
     * same instance.
     */
    private function expandExpression(
        IBooleanExpressionNode $node,
        ISchema $schema,
        string $schemaKey,
        ISchemaId $writtenIn,
        ExpansionTrail $trail,
    ): IBooleanExpressionNode {
        if ($node instanceof AndNode || $node instanceof OrNode) {
            $left = $this->expandExpression($node->leftSide, $schema, $schemaKey, $writtenIn, $trail);
            $right = $this->expandExpression($node->rightSide, $schema, $schemaKey, $writtenIn, $trail);

            return $left === $node->leftSide && $right === $node->rightSide
                ? $node
                : new ($node::class)($left, $right, $node->location);
        }

        if ($node instanceof NotNode) {
            $operand = $this->expandExpression($node->operand, $schema, $schemaKey, $writtenIn, $trail);

            return $operand === $node->operand ? $node : new NotNode($operand, $node->location);
        }

        if ($node instanceof CrossSchemaConditionNode) {
            return $this->expandCheckPredicate($node, $trail);
        }

        if ($node instanceof ConditionNode) {
            $found = $this->constructs->condition($node->conditionKey->name, $writtenIn);

            /* A name no schema declares is left for validation and the compiler
               to report, in the words they use for any other. */
            return $found !== null && $found->definition->isDerived()
                ? $this->expandDerivedCondition($node, $found, $schema, $schemaKey, $trail)
                : $node;
        }

        return $node;
    }
}
