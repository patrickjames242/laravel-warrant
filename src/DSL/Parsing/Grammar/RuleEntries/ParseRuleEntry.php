<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Parsing\ASTNodes\RuleEntryNode;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;

/**
 * One entry of a rule body: an unconditional rule, an `@include` directive, an
 * `if` rule, an ability block or a when block.
 *
 * @extends ParseOneOf<RuleEntryNode>
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
            ParseWhenBlock::class,
        ];
    }
}
