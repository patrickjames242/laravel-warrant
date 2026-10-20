<?php

declare(strict_types=1);

namespace Warrant\Rules;

use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;

interface RuleProvider
{
    /**
     * Return the rules that govern this user's access to the entity in $context,
     * merged with that schema's own `rules()`. The rules are compiled directly to
     * SQL, so the implementation is free to build them however it likes — a
     * database lookup, hardcoded rules, ... — and to return them in whichever
     * form it has them: a rule set, a rule entry (a rule, ability block or
     * include), a rule builder, rule text as a string, {@see BoundSyntax} or
     * {@see WarrantSyntax},
     * or an iterable (array, Collection) of those. They may hold rules for any
     * schema: only the rule sets for `$context->schemaKey`, and the rules with
     * no `for` header, apply to it.
     *
     * @return RuleSetNode|IRuleEntryNode|WarrantRuleBuilder|WarrantSyntax|BoundSyntax|string|iterable<RuleSetNode|IRuleEntryNode|WarrantRuleBuilder|WarrantSyntax|BoundSyntax|string>
     */
    public function rules(RuleProviderContext $context): RuleSetNode|IRuleEntryNode|WarrantRuleBuilder|WarrantSyntax|BoundSyntax|string|iterable;
}
