<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * A placeholder for a value supplied beside the rule text: `:name`, a named
 * binding, or `?`, the next positional one. It may stand wherever an argument
 * may, as the body of an `@sql` reference, and as a denial message.
 *
 * It stays in the tree until {@see \Warrant\DSL\Parsing\BindingResolver} puts
 * the value in its place. A positional binding's value is the one at its place
 * among the `?`s of the text, so it holds no index of its own: the order is
 * where each was written.
 */
final readonly class BindingNode implements INode
{
    /**
     * @param string|null $name The name of a named binding, without its colon;
     *   null for a positional one.
     */
    public function __construct(public ?string $name = null)
    {
    }

    public function isPositional(): bool
    {
        return $this->name === null;
    }
}
