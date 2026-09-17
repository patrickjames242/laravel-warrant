<?php

namespace Warrant\Rules;

/**
 * Something a {@see WarrantRuleSet} holds in its body: a {@see WarrantRule}, or
 * an {@see IncludeInvocation} that expands into rules.
 *
 * The two share one ordered list rather than sitting in lists of their own
 * because where an include was written is part of what it means. Expansion
 * splices the template's rules in at that position, so a reader that keeps only
 * "the rules, then the includes" has already lost the answer.
 */
interface RuleSetEntry
{
}
