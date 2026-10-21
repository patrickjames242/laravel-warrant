<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use LogicException;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A placeholder for a value supplied beside the rule text: `:name`. It may
 * stand wherever an argument may, wherever an ability may, as the body of an
 * `@sql` reference, and as a denial message.
 *
 * It stays in the tree as written, and finds its value by its name among the
 * bindings of the source it was written in. The expander puts the value in its
 * place.
 */
final readonly class BindingNode implements INode
{
    /**
     * @param string $name The binding's name, without its colon.
     */
    public function __construct(public string $name, public SyntaxLocation $location)
    {
    }

    /**
     * Whether the source this binding was written in gives it a value.
     */
    public function hasValue(): bool
    {
        return array_key_exists($this->name, $this->values());
    }

    /**
     * The value the source this binding was written in gives it. Only a syntax
     * validated with its binding values checked is sure to have one.
     */
    public function value(): mixed
    {
        if (! $this->hasValue()) {
            throw new LogicException(sprintf(
                'The binding [:%s] has no value; validate its syntax with binding values checked before reading it.',
                $this->name,
            ));
        }

        return $this->values()[$this->name];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(): array
    {
        return $this->location->source->bound->bindings ?? [];
    }

    public function children(): array
    {
        return [];
    }
}
