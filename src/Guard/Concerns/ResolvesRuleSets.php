<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Expanding\ExpandedRuleSet;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Validation\RuleSetValidator;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;

/**
 * Resolving the ordered {@see RuleSetNode} that governs this guard's user's
 * access to the managed entity: asking the bound {@see RuleProvider}, if any,
 * confirming its answer is about the schema it was asked about, prepending the
 * schema's own rules, and running the set past {@see RuleSetValidator} as written
 * and again expanded, on the way to the compiler.
 *
 * That validation pass reports a mistake earlier than the compiler would, and
 * against every rule in the set rather than the ones a given check reaches —
 * including the rules a template supplied and the expression a derived condition
 * answered with. It is not what makes the set safe to compile: the compiler
 * rejects a name that resolves to nothing at the lookup that needs it.
 *
 * Either provider may answer in any form {@see providedRuleEntries()} reads, which
 * also checks that every rule set among it targets this guard's schema.
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
        $schemaKey = $this->schema::schemaKey();

        $context = new RuleProviderContext(
            schemaKey: $schemaKey,
            schema: $this->schema::class,
            user: $this->user,
            model: $this->schema::model !== '' ? $this->schema::model : null,
        );

        $providerRules = $this->providedRuleEntries(
            app()->bound(RuleProvider::class) ? app(RuleProvider::class)->rules($context) : [],
            'The rule provider',
        );

        $schemaRules = $this->providedRuleEntries(
            $this->schema->rules($context),
            sprintf('The rules() of schema [%s]', $this->schema::class),
        );

        return new RuleSetNode($schemaKey, [...$schemaRules, ...$providerRules]);
    }

    /**
     * The rule entries in what a provider returned, in whichever form it built
     * them: a {@see RuleSetNode}, a rule entry, rule text as a string or
     * {@see WarrantSyntax}, or an iterable of any of these.
     *
     * Every rule set among them must target this guard's schema. A provider is an
     * application's own code, so that is checked rather than assumed: the names
     * in a foreign rule set would otherwise be caught only when one happens to be
     * missing from this schema, and silently compiled when both schemas share the
     * vocabulary.
     *
     * @param  string  $provider  who returned $rules, for the error message
     * @return list<IRuleEntryNode>
     */
    private function providedRuleEntries(mixed $rules, string $provider): array
    {
        if (is_string($rules)) {
            $rules = WarrantSyntax::parse($rules);
        }

        if ($rules instanceof WarrantSyntax) {
            $rules = $rules->isEmpty() || $rules->isRuleEntries()
                ? $rules->ruleEntries()
                : $rules->ruleSets();
        }

        if ($rules instanceof IRuleEntryNode) {
            return [$rules];
        }

        if ($rules instanceof RuleSetNode) {
            if ($rules->schemaKey !== $this->schema::schemaKey()) {
                throw new InvalidArgumentException(sprintf(
                    '%s was asked for schema [%s] but returned a rule set targeting [%s].',
                    $provider,
                    $this->schema::schemaKey(),
                    $rules->schemaKey,
                ));
            }

            return $rules->entries;
        }

        if (is_iterable($rules)) {
            $entries = [];

            foreach ($rules as $rule) {
                array_push($entries, ...$this->providedRuleEntries($rule, $provider));
            }

            return $entries;
        }

        throw new InvalidArgumentException(sprintf(
            '%s returned %s; expected a rule set, a rule entry, rule text, or an iterable of them.',
            $provider,
            get_debug_type($rules),
        ));
    }
}
