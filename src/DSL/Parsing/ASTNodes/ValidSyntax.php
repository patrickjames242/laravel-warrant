<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Validation\RuleValidationException;
use Warrant\DSL\Parsing\Validation\SyntaxValidator;
use Warrant\DSL\Parsing\Validation\ValidationInput;

/**
 * A syntax that has passed validation as written, under the
 * {@see ValidationInput} it holds. Validity is relative to that input: the same
 * text can be valid with no schema and invalid against one, so a syntax is valid
 * only for the input it was validated under.
 *
 * Only {@see of()} and {@see check()} make one, so holding a ValidSyntax is
 * proof that its text was validated.
 *
 * @internal
 */
final readonly class ValidSyntax extends WarrantSyntax
{
    private function __construct(WarrantSyntax $syntax, public ValidationInput $input)
    {
        parent::__construct($syntax->children, $syntax->source, $syntax->positions, $syntax->written, $syntax->bindings);
    }

    /**
     * $syntax, validated as written under $input. Throws the first mistake.
     */
    public static function of(WarrantSyntax $syntax, ValidationInput $input): self
    {
        if ($syntax instanceof self && $syntax->input == $input) {
            return $syntax;
        }

        $mistakes = (new SyntaxValidator($input))->mistakes($syntax);

        if ($mistakes !== []) {
            throw $mistakes[0];
        }

        return new self($syntax, $input);
    }

    /**
     * $syntax validated as written under $input: valid, or invalid with every
     * mistake it makes. Throws nothing.
     */
    public static function check(WarrantSyntax $syntax, ValidationInput $input): self|InvalidSyntax
    {
        if ($syntax instanceof self && $syntax->input == $input) {
            return $syntax;
        }

        $mistakes = (new SyntaxValidator($input))->mistakes($syntax);

        if ($mistakes === []) {
            return new self($syntax, $input);
        }

        /* Every node of a parsed text was recorded, so every mistake has a span;
           the start of the text stands in should one ever not. */
        return new InvalidSyntax($syntax, $input, array_map(static fn (InvalidArgumentException $error): Diagnostic => new Diagnostic(
            $error instanceof RuleValidationException ? $error->reason : $error->getMessage(),
            $error instanceof RuleValidationException ? ($error->span?->offset ?? 0) : 0,
            $error instanceof RuleValidationException ? ($error->span?->endOffset ?? 0) : 0,
            $error instanceof RuleValidationException ? $error->diagnosticCode : DiagnosticCode::InvalidRule,
        ), $mistakes));
    }

    public function resolveBindings(): SyntaxBindingsResolved
    {
        return SyntaxBindingsResolved::of($this);
    }
}
