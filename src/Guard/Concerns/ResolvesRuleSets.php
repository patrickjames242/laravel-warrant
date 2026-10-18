<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SyntaxBindingsResolved;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Validation\GenericMode;
use Warrant\DSL\Parsing\Validation\ValidationInput;
use Warrant\Registry\RegisteredSchemas;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;

/**
 * Resolving the ordered rules that govern this guard's user's access to the
 * managed entity: asking the bound {@see RuleProvider}, if any, confirming its
 * answer is about the schema it was asked about, and prepending the schema's own
 * rules.
 *
 * Each answer goes through the stages of a syntax on its own, before it is
 * combined with the others: validated as written ({@see ValidSyntax}), its
 * bindings' values put in place ({@see SyntaxBindingsResolved}), then expanded
 * ({@see ExpandedSyntax}). An answer given as rule text is validated as that
 * text, so an error in it points into the text that holds it. An answer built in
 * code is validated as the nodes it is. Expanding an answer validates what its
 * templates and derived conditions answer with, each as its own text.
 *
 * That validation reports a mistake earlier than the compiler would, and against
 * every rule in the set rather than the ones a given check reaches. It is not
 * what makes the set safe to compile: the compiler rejects a name that resolves
 * to nothing at the lookup that needs it.
 *
 * Either provider may answer in any form {@see providedSyntaxes()} reads, which
 * also checks that every rule set among it targets this guard's schema.
 *
 * The guard is fixed to one (schema, user), so the rules are resolved, validated
 * and expanded once: every check, filter, diagnosis, and reachability query on
 * this instance reads the same {@see ExpandedSyntax}.
 */
trait ResolvesRuleSets
{
    private ?RuleSetNode $resolvedRuleSet = null;

    private ?ExpandedSyntax $expandedRuleSet = null;

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
    public function expandedRuleSet(): ExpandedSyntax
    {
        $this->resolveOnce();

        return $this->expandedRuleSet;
    }

    /**
     * Resolve, validate and expand the rules, the first time either form of them
     * is asked for.
     *
     * Both forms are kept only once every answer has expanded, so rules that
     * fail are reported again by the next call rather than handed out.
     * Expansion reads no row and no check context, so one expansion serves every
     * check this guard answers.
     */
    private function resolveOnce(): void
    {
        if ($this->expandedRuleSet !== null) {
            return;
        }

        $schemaKey = $this->schema::schemaKey();
        $answers = $this->answers();

        $entries = [];

        foreach ($answers as $answer) {
            array_push($entries, ...$this->entriesOf($answer));
        }

        $expanded = ExpandedSyntax::combine($schemaKey, ...array_map(
            static fn (SyntaxBindingsResolved $answer): ExpandedSyntax => ExpandedSyntax::of($answer),
            $answers,
        ));

        $this->resolvedRuleSet = new RuleSetNode($schemaKey, $entries);
        $this->expandedRuleSet = $expanded;
    }

    /**
     * What the schema's rules() and the rule provider answered with, each
     * validated and with its bindings' values in place, the schema's first.
     *
     * @return list<SyntaxBindingsResolved>
     */
    private function answers(): array
    {
        $context = new RuleProviderContext(
            schemaKey: $this->schema::schemaKey(),
            schema: $this->schema::class,
            user: $this->user,
            model: $this->schema::model !== '' ? $this->schema::model : null,
        );

        $providerRules = $this->providedSyntaxes(
            app()->bound(RuleProvider::class) ? app(RuleProvider::class)->rules($context) : [],
            'The rule provider',
        );

        $schemaRules = $this->providedSyntaxes(
            $this->schema->rules($context),
            sprintf('The rules() of schema [%s]', $this->schema::class),
        );

        return [...$schemaRules, ...$providerRules];
    }

    /**
     * The rules in what a provider returned, in whichever form it built them: a
     * {@see RuleSetNode}, a rule entry, rule text as a string or
     * {@see WarrantSyntax}, or an iterable of any of these. Each is validated
     * against this guard's schema, as written, and then has its bindings' values
     * put in place.
     *
     * Every rule set among them must target this guard's schema. A provider is an
     * application's own code, so that is checked rather than assumed: the names
     * in a foreign rule set would otherwise be caught only when one happens to be
     * missing from this schema, and silently compiled when both schemas share the
     * vocabulary.
     *
     * @param  string  $provider  who returned $rules, for the error message
     * @return list<SyntaxBindingsResolved>
     */
    private function providedSyntaxes(mixed $rules, string $provider): array
    {
        if (is_string($rules)) {
            $rules = WarrantSyntax::parse($rules);
        }

        if ($rules instanceof IRuleEntryNode || $rules instanceof RuleSetNode) {
            $rules = new WarrantSyntax([$rules]);
        }

        if ($rules instanceof WarrantSyntax) {
            foreach ($rules->isSchemaScoped() ? $rules->schemaKeys() : [] as $schemaKey) {
                $this->assertTargetsThisSchema($schemaKey, $provider);
            }

            return [ValidSyntax::of($rules, new ValidationInput(
                new RegisteredSchemas,
                $this->schema::schemaKey(),
                GenericMode::RequireNotGeneric,
            ))->resolveBindings()];
        }

        if (is_iterable($rules)) {
            $syntaxes = [];

            foreach ($rules as $rule) {
                array_push($syntaxes, ...$this->providedSyntaxes($rule, $provider));
            }

            return $syntaxes;
        }

        throw new InvalidArgumentException(sprintf(
            '%s returned %s; expected a rule set, a rule entry, rule text, or an iterable of them.',
            $provider,
            get_debug_type($rules),
        ));
    }

    /**
     * The rule entries $answer holds, as written: its unscoped entries, or the
     * entries of every rule set in it.
     *
     * @return list<IRuleEntryNode>
     */
    private function entriesOf(SyntaxBindingsResolved $answer): array
    {
        if ($answer->isEmpty() || $answer->isRuleEntries()) {
            return $answer->ruleEntries();
        }

        $entries = [];

        foreach ($answer->ruleSets() as $ruleSet) {
            array_push($entries, ...$ruleSet->entries);
        }

        return $entries;
    }

    /**
     * A rule set $provider returned must be about this guard's schema.
     */
    private function assertTargetsThisSchema(string $schemaKey, string $provider): void
    {
        if ($schemaKey !== $this->schema::schemaKey()) {
            throw new InvalidArgumentException(sprintf(
                '%s was asked for schema [%s] but returned a rule set targeting [%s].',
                $provider,
                $this->schema::schemaKey(),
                $schemaKey,
            ));
        }
    }
}
