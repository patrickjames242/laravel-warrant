<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;

/**
 * One entry of a rule body: an unconditional rule, an `@include` directive, an
 * `if` rule or an ability block.
 *
 * @extends ParseOneOf<IRuleEntryNode>
 */
final class ParseRuleEntry extends ParseOneOf
{
    protected static function parsers(): array
    {
        return [
            ParseUnconditionalRule::class,
            ParseIncludeInvocation::class,
            ParseConditionalRule::class,
            ParseAbilityBlock::class,
        ];
    }
}
