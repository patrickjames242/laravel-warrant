<?php

declare(strict_types=1);

namespace Warrant\Rules;

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

interface RuleProvider
{
    /**
     * Return the rules that govern this user's access to the entity in $context,
     * merged with that schema's own `rules()`. The rules are compiled directly to
     * SQL, so the implementation is free to build them however it likes — a
     * database lookup, hardcoded rules, ... — and to return them in whichever
     * form it has them: a rule set, a rule entry (a rule, ability block or
     * include), rule text as a string or {@see WarrantSyntax}, or an iterable
     * (array, Collection) of those. Every rule set among them must target
     * `$context->schemaKey`.
     *
     * @return RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable<RuleSetNode|IRuleEntryNode|WarrantSyntax|string>
     */
    public function rules(RuleProviderContext $context): RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable;
}
