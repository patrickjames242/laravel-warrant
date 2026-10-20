<?php

namespace Warrant\Builders;

use Warrant\Builders\Concerns\PlacesNodes;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * One `@include` in a built rule set, made by `$rule->include(...)` inside
 * {@see Build::ruleSet()}: the rule template to expand, the arguments to hand it,
 * and the abilities its generic clauses take.
 *
 * ```php
 * $rule->include('requires_approval', ['manager'])->for('submit', 'reopen');
 * ```
 *
 * Without {@see for()} the include is generic, as a rule template's body writes
 * it: the `@include` that expands the template names the abilities.
 */
final class IncludeInvocationBuilder
{
    use PlacesNodes;

    /** @var list<string> */
    private array $abilities = [];

    /**
     * @param array<int, mixed> $arguments The template's arguments: values, or
     *   {@see Ref}s.
     */
    public function __construct(
        private readonly string $template,
        private readonly array $arguments = [],
    ) {}

    public function for(string ...$abilities): static
    {
        $this->abilities = array_values($abilities);

        return $this;
    }

    public function toNode(SyntaxSource $source): IncludeInvocationNode
    {
        return new IncludeInvocationNode(
            self::nameNode($this->template, $source),
            array_map(fn (mixed $argument) => self::argumentNode($argument, $source), array_values($this->arguments)),
            array_map(fn (string $ability) => self::nameNode($ability, $source), $this->abilities),
            self::location($source),
        );
    }
}
