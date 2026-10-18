<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\Validation\ValidationInput;

/**
 * A syntax that failed validation as written, under the {@see ValidationInput}
 * it holds, with every mistake it makes. Under another input the same text may
 * be valid, so it can be validated again.
 *
 * Made by {@see ValidSyntax::check()}.
 *
 * @internal
 */
final readonly class InvalidSyntax extends WarrantSyntax
{
    /**
     * @param list<Diagnostic> $diagnostics Every mistake, in the order found.
     */
    public function __construct(WarrantSyntax $syntax, public ValidationInput $input, public array $diagnostics)
    {
        parent::__construct($syntax->children, $syntax->source, $syntax->positions, $syntax->written, $syntax->bindings);
    }

    /**
     * Throws the first mistake this syntax makes under its input: an invalid
     * syntax has no values to put in place.
     */
    public function resolveBindings(): SyntaxBindingsResolved
    {
        return ValidSyntax::of($this, $this->input)->resolveBindings();
    }
}
