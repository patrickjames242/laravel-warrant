<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Parsing\Validation\RuleSetValidator;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\WarrantRuleSet;

/**
 * Resolving the ordered {@see WarrantRuleSet} that governs this guard's user's
 * access to the managed entity: asking the bound {@see RuleResolver}, confirming
 * its answer is about the schema it was asked about, prepending the schema's
 * implicit rules, and validating the result before it is compiled.
 *
 * The resolver is an application's own class and may build a rule set however it
 * likes, so which schema it targets is worth checking rather than assuming. The
 * names in it are validated against this guard's schema either way, so a foreign
 * rule set would otherwise be caught only when a condition it names happens to be
 * one this schema does not declare — and silently compiled when both schemas
 * share the vocabulary.
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

        if ($ruleSet->schemaKey !== $this->schema::schemaKey()) {
            throw new InvalidArgumentException(sprintf(
                'The rule resolver was asked for schema [%s] but returned a rule set targeting [%s].',
                $this->schema::schemaKey(),
                $ruleSet->schemaKey,
            ));
        }

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
