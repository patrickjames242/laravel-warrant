<?php

namespace Warrant\Schema\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\RuleSetValidator;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

/**
 * Resolving the ordered {@see WarrantRuleSet} that governs a user's access to the
 * managed entity: asking the bound {@see RuleResolver}, prepending the schema's
 * implicit rules, and validating the result before it is compiled.
 */
trait ResolvesRuleSets
{
    /**
     * Resolve and validate the rule set that governs this user's access to the
     * managed entity.
     */
    protected function resolveRuleSet(Authenticatable $currentUser): WarrantRuleSet
    {
        $resolver = app(RuleResolver::class);

        $ruleSet = $resolver->resolve(new RuleResolutionContext(
            schemaKey: static::schemaKey(),
            schema: static::class,
            user: $currentUser,
            model: static::model !== '' ? static::model : null,
        ));

        $implicitRules = $this->implicitRules();

        if ($implicitRules !== []) {
            $ruleSet = new WarrantRuleSet($ruleSet->schemaKey, [
                ...$implicitRules,
                ...$ruleSet->rules,
            ]);
        }

        (new RuleSetValidator($this, static::schemaKey()))->validate($ruleSet);

        return $ruleSet;
    }
}
