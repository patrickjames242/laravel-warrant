<?php

namespace Warrant\Schema\RuleTemplates;

/**
 * A schema that declares `#[RuleTemplate]` methods: generic rules an `@include`
 * expands, applying the abilities it names.
 */
interface IProvidesRuleTemplates
{
    /**
     * @return list<RuleTemplateDefinition> The `#[RuleTemplate]` methods.
     */
    public function ruleTemplates(): array;

    /**
     * The template keyed $key, or null when the schema declares none.
     */
    public function ruleTemplate(string $key): ?RuleTemplateDefinition;

    /**
     * Calls $template's method with the include's arguments, preceded by $context
     * when the template takes one. Answers with rule text, syntax, a builder, or
     * an iterable of them.
     *
     * @param array<int, mixed> $arguments The resolved DSL arguments, in order.
     */
    public function invokeRuleTemplate(RuleTemplateDefinition $template, ?RuleTemplateContext $context, array $arguments): mixed;
}
