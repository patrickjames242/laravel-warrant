<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A handle that names a schema by key: `schema`, which selects no row, or
 * `schema(<arg>, …)`, whose arguments are bound positionally to the schema's
 * row key.
 *
 * {@see $hasRowSelector} is kept apart from {@see $boundKey} because an empty
 * argument list is not an absent one: `schema()` addresses a row with a key that
 * takes no arguments, while bare `schema` addresses no row at all.
 *
 * The elements of {@see $boundKey} hold what was written: a {@see LiteralNode},
 * a {@see BindingNode} whose value expansion puts in its place, a symbolic
 * {@see ContextRef} filled per check, or a symbolic {@see ColumnRef} resolved to
 * a column of the enclosing frame.
 */
final readonly class SchemaHandleNode implements IHandleNode
{
    /**
     * @param NameNode $schemaKey The schema the handle reaches.
     * @param bool $hasRowSelector Whether the handle was written with parentheses.
     * @param list<INode> $boundKey The row selector's arguments. Empty on an
     *   unbound handle, and on `schema()`.
     */
    public function __construct(
        public NameNode $schemaKey,
        public bool $hasRowSelector,
        public array $boundKey,
        public SyntaxLocation $location,
    ) {
    }

    public function isRowBound(): bool
    {
        return $this->hasRowSelector;
    }

    public function children(): array
    {
        return [$this->schemaKey, ...$this->boundKey];
    }
}
