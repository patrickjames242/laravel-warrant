<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Validation\ValidationInput;
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
 * combined with the others. It is handed over: made a {@see WarrantSyntax}
 * whose source says what answered with it, the schema's `rules()` or the rule
 * provider. It is validated as written ({@see ValidSyntax}), as what answered
 * with it says: against this guard's schema, every rule naming its abilities.
 * Then it is expanded ({@see ExpandedSyntax}), which puts the values of its
 * bindings in place and validates what its templates and derived conditions
 * answer with, each as its own text. An answer given as rule text is validated
 * as that text, so an error in it points into the text that holds it; one built
 * in code is validated as the nodes it is.
 *
 * That validation reports a mistake earlier than the compiler would, and against
 * every rule in the set rather than the ones a given check reaches. It is not
 * what makes the set safe to compile: the compiler rejects a name that resolves
 * to nothing at the lookup that needs it.
 *
 * Either may answer in any form {@see handedOver()} reads, which also checks
 * that every rule set among it targets this guard's schema.
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

        $expanded = ExpandedSyntax::combine($schemaKey, self::validationInput(), ...array_map(
            static fn (ValidSyntax $answer): ExpandedSyntax => ExpandedSyntax::expand($answer),
            $answers,
        ));

        $this->resolvedRuleSet = new RuleSetNode(
            new NameNode($schemaKey, SyntaxLocation::noProducer()),
            $entries,
            SyntaxLocation::noProducer(),
        );
        $this->expandedRuleSet = $expanded;
    }

    /**
     * What the schema's rules() and the rule provider answered with, each handed
     * over and validated, the schema's first.
     *
     * @return list<ValidSyntax>
     */
    private function answers(): array
    {
        $schemaKey = $this->schema::schemaKey();

        $context = new RuleProviderContext(
            schemaKey: $schemaKey,
            schema: $this->schema::class,
            user: $this->user,
            model: $this->schema::model !== '' ? $this->schema::model : null,
        );

        $providerRules = [];

        if (app()->bound(RuleProvider::class)) {
            $provider = app(RuleProvider::class);
            $providerRules = $this->handedOver($provider->rules($context), SyntaxProducer::ruleProvider($schemaKey, $provider::class));
        }

        $schemaRules = $this->handedOver(
            $this->schema->rules($context),
            SyntaxProducer::schemaRules($schemaKey, $this->schema::class),
        );

        return [...$schemaRules, ...$providerRules];
    }

    /**
     * The rules in what $producer answered with, in whichever form it built
     * them: a {@see RuleSetNode}, a rule entry, a {@see WarrantRuleBuilder},
     * rule text as a string or {@see WarrantSyntax}, or an iterable of any of
     * these. Text is parsed, and rules built in code are placed, in a source
     * that says $producer answered with them; a syntax the application made
     * itself has $producer set on its source. Each is then validated as
     * written.
     *
     * Every rule set among them must target this guard's schema. A provider is an
     * application's own code, so that is checked rather than assumed: the names
     * in a foreign rule set would otherwise be caught only when one happens to be
     * missing from this schema, and silently compiled when both schemas share the
     * vocabulary.
     *
     * @return list<ValidSyntax>
     */
    private function handedOver(mixed $rules, SyntaxProducer $producer): array
    {
        if (is_string($rules)) {
            return [$this->validated(WarrantParser::parse($rules, $producer), $producer)];
        }

        if ($rules instanceof WarrantRuleBuilder) {
            $rules = $rules->toRule();
        }

        if ($rules instanceof IRuleEntryNode || $rules instanceof RuleSetNode) {
            return [$this->validated(new WarrantSyntax([$rules], new SyntaxSource(null, $producer)), $producer)];
        }

        if ($rules instanceof WarrantSyntax) {
            $rules->source->setProducer($producer);

            return [$this->validated($rules, $producer)];
        }

        if (is_iterable($rules)) {
            $syntaxes = [];

            foreach ($rules as $rule) {
                array_push($syntaxes, ...$this->handedOver($rule, $producer));
            }

            return $syntaxes;
        }

        throw new InvalidArgumentException(sprintf(
            '%s returned %s; expected a rule set, a rule entry, a rule builder, rule text, or an iterable of them.',
            $producer->describe(),
            get_debug_type($rules),
        ));
    }

    /**
     * $syntax, which $producer answered with, validated as written once it is
     * found to target this guard's schema.
     */
    private function validated(WarrantSyntax $syntax, SyntaxProducer $producer): ValidSyntax
    {
        foreach ($syntax->isSchemaScoped() ? $syntax->schemaKeys() : [] as $schemaKey) {
            $this->assertTargetsThisSchema($schemaKey, $producer);
        }

        return ValidSyntax::of($syntax, self::validationInput());
    }

    /**
     * What every answer is validated against: the schemas the application
     * registers. What answered with it says the rest.
     */
    private static function validationInput(): ValidationInput
    {
        return new ValidationInput(new RegisteredSchemas);
    }

    /**
     * The rule entries $answer holds, as written: its unscoped entries, or the
     * entries of every rule set in it.
     *
     * @return list<IRuleEntryNode>
     */
    private function entriesOf(ValidSyntax $answer): array
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
     * A rule set $producer answered with must be about this guard's schema.
     */
    private function assertTargetsThisSchema(string $schemaKey, SyntaxProducer $producer): void
    {
        if ($schemaKey !== $this->schema::schemaKey()) {
            throw new InvalidArgumentException(sprintf(
                '%s was asked for schema [%s] but returned a rule set targeting [%s].',
                $producer->describe(),
                $this->schema::schemaKey(),
                $schemaKey,
            ));
        }
    }
}
