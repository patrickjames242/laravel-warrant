<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * Something written under a `for <schema>` header: a {@see RuleSetNode}, or a
 * {@see SchemaConditionNode}. The header names the schema whose conditions and
 * abilities the names inside belong to.
 */
interface ISchemaScopedNode extends INode
{
    /**
     * The key of the schema the `for` header names.
     */
    public function schemaKey(): string;
}
