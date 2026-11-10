<?php

namespace Warrant\Schema\Relations;

use Attribute;
use InvalidArgumentException;

/**
 * Declares a method as a row relation: how a row of this schema reaches rows of
 * another. Rule text follows it by name, as in `can(view for its folder)`.
 *
 * ```php
 * #[RowRelation(FolderSchema::class)]
 * public function folder(RowRelationContext $c): Builder
 * {
 *     return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RowRelation
{
    /**
     * @param class-string $schema The row schema whose rows the relation reaches.
     * @param string|null $key The name rule text uses after `its`: the method's
     *   name in snake case when omitted.
     */
    public function __construct(public string $schema, public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('RowRelation key cannot be empty.');
        }
    }
}
