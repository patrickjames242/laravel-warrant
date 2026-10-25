<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * One entry of a `with` map, `key = value`: a value of the target schema's
 * context, under its key. A key written twice in one map is read twice; that
 * is the validator's to report.
 */
final readonly class WithEntryNode implements Node
{
    /**
     * @param Node $value An argument: a literal, a binding, or an `@context`,
     *   `@column` or `@sql` reference.
     */
    public function __construct(public NameNode $key, public Node $value, public SyntaxLocation $location) {}

    public function children(): array
    {
        return [
            $this->key,
            $this->value,
        ];
    }
}
