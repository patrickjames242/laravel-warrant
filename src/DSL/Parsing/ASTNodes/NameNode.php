<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A name as the text wrote it: a condition, template or schema key, an ability
 * or `*`, an alias, a column, or a context key. What it names is the field of
 * the node that holds it. A name the text should have written and did not is
 * {@see Node::MISSING_NAME}, placed where it is missing.
 */
final readonly class NameNode implements Node
{
    public function __construct(public string $name, public SyntaxLocation $location) {}

    public function children(): array
    {
        return [];
    }
}
