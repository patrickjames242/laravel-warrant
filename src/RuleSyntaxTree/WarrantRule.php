<?php

namespace Warrant\RuleSyntaxTree;

use Closure;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\WarrantDenialContext;

readonly class WarrantRule
{

    /**
     * @param string|Closure(WarrantDenialContext):(string|\Throwable)|null $message
     *   An optional denial message surfaced when this rule denies access to a
     *   singular target. Only ever surfaced for a matching `cannot` clause (a
     *   `can` rule is never the attributable cause of a denial). A string is
     *   wrapped in a {@see \Warrant\WarrantAuthorizationException}; a closure
     *   receives the denial context and returns either a string (wrapped) or a
     *   Throwable (thrown as-is). Not expressible in the string DSL and dropped
     *   by {@see toSyntax()} / {@see toBoundSyntax()}.
     */
    public function __construct(
        public ?IBooleanExpressionNode $conditions,
        public array $canAbilities,
        public array $cannotAbilities,
        public string|Closure|null $message = null,
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
     * Return a copy of this rule carrying a denial message. Works for any rule,
     * however it was constructed — notably a {@see fromSyntax()} rule, which the
     * string DSL cannot give a message. See {@see $message}.
     *
     * Only surfaced for a matching `cannot` clause; attaching a message to a rule
     * with no `cannot` is rejected at validation time, whatever the construction
     * path.
     *
     * @param string|Closure(WarrantDenialContext):(string|\Throwable) $message
     */
    public function withDenialMessage(string|Closure $message): self
    {
        return new self($this->conditions, $this->canAbilities, $this->cannotAbilities, $message);
    }

    /**
     * Render this rule back to the string DSL with scalar condition parameters
     * inlined as literals. Throws if a parameter has no inline representation —
     * use {@see toBoundSyntax()} for those. Round-trips via {@see fromSyntax()}.
     *
     * Note: an attached {@see $message} is dropped — the DSL cannot express it —
     * so a message-bearing rule does not round-trip losslessly through syntax.
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