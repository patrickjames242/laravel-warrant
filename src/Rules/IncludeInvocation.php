<?php

namespace Warrant\Rules;

/**
 * One `@include` in a rule set: the rule template to expand, the arguments to
 * hand it, and the abilities its headless clauses take.
 *
 * The abilities are settled here rather than at expansion because both ways of
 * writing an include name them in the text — the enclosing ability block's
 * header, or the reference's own `for` list — so the parser already knows them.
 * What is left for the expansion is the template's body, which only the schema
 * can answer with.
 *
 * An invocation is deliberately not part of a {@see WarrantRule}. A rule holds
 * concrete abilities and a condition tree; an include holds neither until it is
 * expanded, so it rides beside the rules on {@see WarrantRuleSet} instead of
 * inside one.
 */
final readonly class IncludeInvocation implements RuleSetEntry
{
    /**
     * @param list<mixed> $arguments The template's DSL arguments, resolved as a
     *   condition's are: literals, bindings already substituted, and the symbolic
     *   `@context` / `@column` references, which stay unresolved until a compile.
     * @param list<string> $abilities The abilities the expanded clauses apply to.
     *   `*` is permitted, as it is in any ability list.
     */
    public function __construct(
        public string $templateKey,
        public array $arguments = [],
        public array $abilities = [],
    ) {
    }
}
