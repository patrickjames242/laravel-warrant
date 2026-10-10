<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * Something written under a `for <schema>` header: a {@see RuleSetNode}, or a
 * {@see SchemaConditionNode}. The header names the schema whose conditions and
 * abilities the names inside belong to.
 */
interface ISchemaScopedNode extends INode
{
    /* The name a {@see \Warrant\DSL\Parsing\Positions\SourceMap} records the
       header's schema under: the property it is stored in. */
    public const PART_SCHEMA_KEY = 'schemaKey';

    /**
     * The key of the schema the `for` header names.
     */
    public function schemaKey(): string;
}
