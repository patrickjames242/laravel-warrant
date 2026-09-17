<?php

namespace Warrant\Rules;

/**
 * A rule template's body: headless rule text, plus the values for any `:name` /
 * `?` placeholders in it.
 *
 * A template is the one member of the rule family that is not parsed when it is
 * made. Its clauses name no abilities — they take them from the ability block or
 * the `for` list at the `@include` that expands it — so there is nothing to parse
 * the text into until a reference supplies them. It therefore carries its syntax
 * as text and is read at each expansion.
 *
 * Bindings are what let a template take a value without writing it into the text.
 * Interpolating one is both unsafe (a quote in a string ends the literal early)
 * and, for a closure denial message, impossible: a closure has no inline form and
 * reaches the DSL only through a binding.
 *
 * A body's placeholders are its own. Each parse gets its own binding state, so a
 * template written with `:named` placeholders expands cleanly inside a rule set
 * parsed with positional `?` ones — the rule against mixing the two forms is per
 * parse, not global.
 *
 * A `#[RuleTemplate]` method may answer with one of these, or with a plain string
 * when its body has no placeholders to fill.
 */
readonly class WarrantRuleTemplate
{
    /**
     * @param array<int|string, mixed> $bindings Values for the `:name` / `?`
     *   placeholders in $syntax. Named and positional may not be mixed within one
     *   body, as anywhere else.
     */
    public function __construct(
        public string $syntax,
        public array $bindings = [],
    ) {
    }
}
