<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A value as the text wrote it: a string, a number, a boolean or null. A value
 * given in code, such as a closure for a denial message, is held in one too,
 * built by the code that gave it.
 */
final readonly class LiteralNode implements Node
{
    public function __construct(public mixed $value, public SyntaxLocation $location) {}

    public function children(): array
    {
        return [];
    }
}
