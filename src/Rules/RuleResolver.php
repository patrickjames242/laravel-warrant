<?php

declare(strict_types=1);

namespace Warrant\Rules;

use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;

interface RuleResolver
{
    /**
     * Return the rule set that governs this user's access to the entity in
     * $context. The rule set is compiled directly to SQL, so the implementation
     * is free to build it however it likes (parsed rule text scoped with
     * `WarrantSyntax::parse($text)->scopedTo($context->schemaKey)`, a database
     * lookup, hardcoded rules, ...).
     */
    public function resolve(RuleResolutionContext $context): RuleSetNode;
}
