<?php

namespace Warrant\Schema\Rules;

use Warrant\Rules\RuleProviderContext;

/**
 * A schema with rules of its own, merged ahead of whatever the global
 * {@see \Warrant\Rules\IRuleProvider} returns.
 */
interface IProvidesRules
{
    /**
     * The schema's rules for the user $context names: a rule set, a rule entry, a
     * rule builder, rule text (as a string or with its bindings), or an iterable
     * of those. Every rule set among them must be for this schema.
     */
    public function rules(RuleProviderContext $context): mixed;
}
