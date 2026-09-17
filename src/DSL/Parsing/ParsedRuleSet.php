<?php

namespace Warrant\DSL\Parsing;

use Warrant\Rules\RuleSetEntry;

/**
 * The parser's raw structural output for one rule-set block: the schema key
 * named by a `for <schema>` header (null when the header is absent), plus the
 * block's body — its rules and `@include` directives, in the order they were
 * written.
 *
 * Header/param reconciliation and the "a rule set must name a schema" decision
 * live in the factories ({@see \Warrant\Rules\WarrantRuleSet},
 * {@see \Warrant\Rules\RuleSetGroup}), not in the parser — the parser
 * reports only what the syntax said.
 */
final readonly class ParsedRuleSet
{
    /**
     * @param list<RuleSetEntry> $rules
     */
    public function __construct(
        public ?string $schemaKey,
        public array $rules,
    ) {
    }
}
