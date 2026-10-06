<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Expanding\ExpandedRuleSet;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\Validation\RuleSetValidator;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;

/**
 * Resolving the ordered {@see RuleSetNode} that governs this guard's user's
 * access to the managed entity: asking the bound {@see RuleResolver}, confirming
 * its answer is about the schema it was asked about, prepending the schema's
 * implicit rules, and running the set past {@see RuleSetValidator} as written
 * and again expanded, on the way to the compiler.
 *
 * That validation pass reports a mistake earlier than the compiler would, and
 * against every rule in the set rather than the ones a given check reaches —
 * including the rules a template supplied and the expression a derived condition
 * answered with. It is not what makes the set safe to compile: the compiler
 * rejects a name that resolves to nothing at the lookup that needs it.
 *
 * The resolver is an application's own class and may build a rule set however it
 * likes, so which schema it targets is worth checking rather than assuming. The
 * names in it are validated against this guard's schema either way, so a foreign
 * rule set would otherwise be caught only when a condition it names happens to be
 * one this schema does not declare — and silently compiled when both schemas
 * share the vocabulary.
 *
 * The guard is fixed to one (schema, user), so the rule set is resolved, expanded
 * and validated once: every check, filter, diagnosis, and reachability query on
 * this instance reads the same {@see ExpandedRuleSet}.
 */
trait ResolvesRuleSets
{
    private ?RuleSetNode $resolvedRuleSet = null;

    private ?ExpandedRuleSet $expandedRuleSet = null;

    /**
     * This guard's resolved, validated rule set, memoized for the instance. It is
     * the rule set as written — ability blocks and includes intact — which is what
     * writing it back out wants; compiling wants {@see expandedRuleSet()}.
     */
    public function resolvedRuleSet(): RuleSetNode
    {
        $this->resolveOnce();

        return $this->resolvedRuleSet;
    }

    /**
     * This guard's validated rule set after the expansion phase — rules alone,
     * every ability block opened up, every include replaced by its template's
     * rules and every derived condition by its expression — memoized for the
     * instance.
     */
    public function expandedRuleSet(): ExpandedRuleSet
    {
        $this->resolveOnce();

        return $this->expandedRuleSet;
    }

    /**
     * Resolve, expand and validate the rule set, the first time either form of it
     * is asked for.
     *
     * Both forms are kept only once the expansion has validated, so a rule set
     * that fails is reported again by the next call rather than handed out.
     * Expansion reads no row and no check context, so one expansion serves every
     * check this guard answers.
     */
    private function resolveOnce(): void
    {
        if ($this->expandedRuleSet !== null) {
            return;
        }

        $validator = new RuleSetValidator($this->schema, $this->schema::schemaKey());

        $ruleSet = $this->resolveRuleSet();
        $validator->validateWritten($ruleSet);

        $expanded = (new RuleSetExpander)->expand($ruleSet, $this->schema);
        $validator->validateExpanded($expanded);

        $this->resolvedRuleSet = $ruleSet;
        $this->expandedRuleSet = $expanded;
    }

    private function resolveRuleSet(): RuleSetNode
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

        if ($implicitRules instanceof RuleSetNode) {
            if ($implicitRules->schemaKey !== $ruleSet->schemaKey) {
                throw new InvalidArgumentException(sprintf(
                    'Implicit rule set for schema [%s] targets a different schema [%s].',
                    $ruleSet->schemaKey,
                    $implicitRules->schemaKey,
                ));
            }

            $implicitRules = $implicitRules->entries;
        }

        if ($implicitRules !== []) {
            $ruleSet = new RuleSetNode($ruleSet->schemaKey, [
                ...$implicitRules,
                ...$ruleSet->entries,
            ]);
        }

        return $ruleSet;
    }
}
