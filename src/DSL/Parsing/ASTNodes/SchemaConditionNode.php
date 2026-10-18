<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A condition expression written under a `for <schema>` header. The header says
 * which schema's conditions the names in the expression are, which an expression
 * alone cannot say; it changes nothing about the expression itself.
 */
final readonly class SchemaConditionNode implements ISchemaScopedNode
{
    public function __construct(
        public NameNode $schemaKey,
        public IBooleanExpressionNode $expression,
        public SyntaxLocation $location,
    ) {
    }

    public function schemaKey(): string
    {
        return $this->schemaKey->name;
    }
}
