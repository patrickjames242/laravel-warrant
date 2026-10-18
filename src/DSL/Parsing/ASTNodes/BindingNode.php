<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use LogicException;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A placeholder for a value supplied beside the rule text: `:name`, a named
 * binding, or `?`, the next positional one. It may stand wherever an argument
 * may, as the body of an `@sql` reference, and as a denial message.
 *
 * It stays in the tree as written, and finds its value among the bindings of
 * the source it was written in: a named binding by its name, a positional one
 * by its index, its place among the `?`s of that source. The expander puts the
 * value in its place.
 */
final readonly class BindingNode implements INode
{
    /**
     * @param string|null $name The name of a named binding, without its colon;
     *   null for a positional one.
     * @param int|null $index A positional binding's place among the `?`s of its
     *   source, from 0; null for a named one.
     */
    public function __construct(public ?string $name, public ?int $index, public SyntaxLocation $location)
    {
    }

    public function isPositional(): bool
    {
        return $this->name === null;
    }

    /**
     * Whether the source this binding was written in gives it a value.
     */
    public function hasValue(): bool
    {
        return array_key_exists($this->key(), $this->values());
    }

    /**
     * The value the source this binding was written in gives it. Only a syntax
     * validated with its binding values checked is sure to have one.
     */
    public function value(): mixed
    {
        if (! $this->hasValue()) {
            throw new LogicException(sprintf(
                'The binding [%s] has no value; validate its syntax with binding values checked before reading it.',
                $this->isPositional() ? '?' : ':'.$this->name,
            ));
        }

        return $this->values()[$this->key()];
    }

    private function key(): int|string
    {
        return $this->name ?? $this->index;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function values(): array
    {
        $bindings = $this->location->source->bound->bindings ?? [];

        return $this->isPositional() ? array_values($bindings) : $bindings;
    }
}
