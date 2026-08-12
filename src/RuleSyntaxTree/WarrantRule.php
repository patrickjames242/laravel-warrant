<?php

namespace Warrant\RuleSyntaxTree;

use Warrant\RuleSyntaxTree\Parsing\WarrantParser;

readonly class WarrantRule
{

    public function __construct(
        public ?IBooleanExpressionNode $conditions,
        public array $canAbilities,
        public array $cannotAbilities,
    ){

    }

    /**
     * Build a single rule by parsing raw Warrant syntax, resolving any
     * named (:name) or positional (?) placeholders against $bindings.
     */
    public static function fromSyntax(
        string $syntax,
        array $bindings = [],
    ): self {
        return WarrantParser::parseSingleRule($syntax, $bindings);
    }

    /**
     * Start a fluent, query-builder-style rule construction.
     */
    public static function build(): WarrantRuleBuilder
    {
        return new WarrantRuleBuilder;
    }

    /**
     * Render this rule back to the string DSL with scalar condition parameters
     * inlined as literals. Throws if a parameter has no inline representation —
     * use {@see toBoundSyntax()} for those. Round-trips via {@see fromSyntax()}.
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::toSyntax($this);
    }

    /**
     * Render this rule to `?`-parameterized syntax plus the positional bindings
     * that fill it. Lossless for any parameter value. Round-trips via
     * `WarrantRule::fromSyntax($result->syntax, $result->bindings)`.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::toBoundSyntax($this);
    }

}