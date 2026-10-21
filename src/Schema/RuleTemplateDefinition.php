<?php

namespace Warrant\Schema;

/**
 * A schema's rule template, resolved from a `#[RuleTemplate]` method: its DSL
 * key, the name of the method that answers with the template's body, how many
 * DSL arguments it requires, and how it is told the abilities.
 *
 * A template's body is generic — its clauses name no abilities, taking them from
 * the ability block or the `for` list at the `@include` that expands it. A
 * template may be told which abilities those are, through a
 * {@see RuleTemplateContext}, and choose what its rules say by them, but the
 * abilities still belong to the reference.
 *
 * This is a plain value — it carries the method *name*, not a reflection handle —
 * so it is the single object a schema's template resolution returns and any
 * vocabulary source (including a test double) can construct one directly.
 */
final readonly class RuleTemplateDefinition
{
    /**
     * @param int $requiredArgumentCount The number of DSL arguments the template
     *   requires — its parameters with no default value, not counting a leading
     *   {@see RuleTemplateContext}. Supplying fewer is rejected wherever the
     *   template is expanded, and by validation ahead of that for a rule written
     *   as text.
     * @param bool $takesContext Whether the method's first parameter is a
     *   {@see RuleTemplateContext}, handed before the DSL arguments.
     * @param bool $callOncePerAbility Whether the method is called once for each
     *   ability the include names rather than once for all of them.
     */
    public function __construct(
        public string $key,
        public string $methodName,
        public int $requiredArgumentCount = 0,
        public bool $takesContext = false,
        public bool $callOncePerAbility = false,
    ) {}
}
