<?php

namespace Warrant\Schema\Relations;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A schema whose rows reach other schemas' rows through `#[RowRelation]` methods.
 */
interface IProvidesRowRelations
{
    /**
     * @return list<RowRelationDefinition> Every row relation, in declaration order.
     */
    public function rowRelations(): array;

    /**
     * The row relation rule text names $key, or null when the schema declares none.
     */
    public function rowRelation(string $key): ?RowRelationDefinition;

    /**
     * Calls the relation's method with $c, and answers with the query it
     * constrained.
     */
    public function invokeRowRelation(RowRelationDefinition $relation, RowRelationContext $c): QueryBuilder;
}
