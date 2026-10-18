<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\BindingResolver;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\DSL\Parsing\Validation\ValidationInput;

/**
 * A valid syntax with the value of every binding in its place, the tree as
 * written kept in {@see $written}. It holds the {@see ValidationInput} its text
 * was validated under, which is also what it expands against.
 *
 * Only {@see of()} makes one, and only from a {@see ValidSyntax}, so holding a
 * SyntaxBindingsResolved is proof that its text was validated before any value
 * was put in it.
 *
 * @internal
 */
final readonly class SyntaxBindingsResolved extends WarrantSyntax
{
    /**
     * @param list<INode> $children The children with their bindings' values in place.
     */
    private function __construct(ValidSyntax $syntax, array $children, public ValidationInput $input)
    {
        parent::__construct($children, $syntax->source, $syntax->positions, $syntax->written);
    }

    /**
     * $syntax with the value of every binding in its place. Throws when the
     * bindings do not fit the placeholders.
     */
    public static function of(ValidSyntax $syntax): self
    {
        $children = (new BindingResolver((string) $syntax->source, $syntax->positions ?? new SourceMap))->resolve($syntax);

        return new self($syntax, $children, $syntax->input);
    }

    public function resolveBindings(): self
    {
        return $this;
    }
}
