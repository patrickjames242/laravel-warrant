<?php

namespace Warrant\Schema;

/**
 * A schema's rule template, resolved from a `#[RuleTemplate]` method: its DSL
 * key, the name of the method that answers with the template's body, and how many
 * DSL arguments it requires.
 *
 * A template's body is headless — its clauses name no abilities, taking them from
 * the ability block or the `for` list at the `@include` that expands it. That is
 * why the definition says nothing about abilities: a template is a shape, and the
 * abilities belong to the reference.
 *
 * This is a plain value — it carries the method *name*, not a reflection handle —
 * so it is the single object a schema's template resolution returns and any
 * vocabulary source (including a test double) can construct one directly.
 */
final readonly class RuleTemplateDefinition
{
    /**
     * @param int $requiredArgumentCount The number of DSL arguments the template
     *   requires — its parameters with no default value. Unlike a condition, a
     *   template takes no leading context object: it is handed argument values and
     *   answers with text, reading no row and no query, so every parameter is an
     *   argument. Supplying fewer is rejected wherever the template is expanded,
     *   and by validation ahead of that for a rule written as text.
     */
    public function __construct(
        public string $key,
        public string $methodName,
        public int $requiredArgumentCount = 0,
    ) {}
}
