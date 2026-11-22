<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A name standing for a schema constant, written `<name>` or
 * `<schema>.<name>` where an argument goes: `payroll_module`,
 * `users.view_payroll`.
 *
 * It stays in the tree as written. Which constant it names is looked up from
 * where it was written, and the expander puts the constant's value in its
 * place.
 */
final readonly class ConstantRef implements INode
{
    /**
     * @param NameNode|null $schema The key of the schema written before the
     *   dot, whose own constants alone the name is looked up in. Null for a
     *   bare name, looked up as a condition is.
     * @param NameNode $name The constant's name.
     */
    public function __construct(
        public ?NameNode $schema,
        public NameNode $name,
        public SyntaxLocation $location,
    ) {
    }

    /**
     * The reference as written: `<name>` or `<schema>.<name>`.
     */
    public function written(): string
    {
        return ($this->schema === null ? '' : $this->schema->name.'.').$this->name->name;
    }

    public function children(): array
    {
        return [
            ...($this->schema === null ? [] : [$this->schema]),
            $this->name,
        ];
    }
}
