<?php

namespace Warrant\Schema\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
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
     * Public entry to this schema's resolved, validated rule set for a user.
     * Used by the compiler when a cross-schema `can(...)` reference must resolve
     * another schema's rules; internal callers use {@see resolveRuleSet}.
     */
    public function resolvedRuleSet(Authenticatable $currentUser): WarrantRuleSet
    {
        return $this->resolveRuleSet($currentUser);
    }

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

        if ($implicitRules instanceof WarrantRuleSet) {
            if ($implicitRules->schemaKey !== $ruleSet->schemaKey) {
                throw new InvalidArgumentException(sprintf(
                    'Implicit rule set for schema [%s] targets a different schema [%s].',
                    $ruleSet->schemaKey,
                    $implicitRules->schemaKey,
                ));
            }

            $implicitRules = $implicitRules->rules;
        }

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
