<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\RuleSetValidator;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

/**
 * Resolving the ordered {@see WarrantRuleSet} that governs this guard's user's
 * access to the managed entity: asking the bound {@see RuleResolver}, prepending
 * the schema's implicit rules, and validating the result before it is compiled.
 *
 * The guard is fixed to one (schema, user), so the resolved rule set is memoized
 * once and reused by every check, filter, diagnosis, and reachability query on
 * this instance.
 */
trait ResolvesRuleSets
{
    private ?WarrantRuleSet $resolvedRuleSet = null;

    /**
     * This guard's resolved, validated rule set, memoized for the instance.
     */
    public function resolvedRuleSet(): WarrantRuleSet
    {
        return $this->resolvedRuleSet ??= $this->resolveRuleSet();
    }

    private function resolveRuleSet(): WarrantRuleSet
    {
        $resolver = app(RuleResolver::class);

        $ruleSet = $resolver->resolve(new RuleResolutionContext(
            schemaKey: $this->schema::schemaKey(),
            schema: $this->schema::class,
            user: $this->user,
            model: $this->schema::model !== '' ? $this->schema::model : null,
        ));

        $implicitRules = $this->schema->implicitRules();

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

        (new RuleSetValidator($this->schema, $this->schema::schemaKey()))->validate($ruleSet);

        return $ruleSet;
    }
}
