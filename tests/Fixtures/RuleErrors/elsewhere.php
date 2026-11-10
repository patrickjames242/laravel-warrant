<?php

use Warrant\DSL\Parsing\BoundSyntax;

/** Rule text a schema's rules() returns from another file, through warrant(). */
function ruleErrorsThroughWarrant(): BoundSyntax
{
    return warrant(<<<'WARRANT'
        if is_owner
            and is_ownr
        they can view
        WARRANT);
}

/** Rule text a schema's rules() returns from another file, as a plain string. */
function ruleErrorsAsPlainString(): string
{
    return <<<'WARRANT'
        if is_owner
            and is_ownr
        they can view
        WARRANT;
}
