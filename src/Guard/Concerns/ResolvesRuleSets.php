<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxProducerKind;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Registry\RegisteredSchemas;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;

/**
 * The rules that govern this guard's user's access to the managed entity: what
 * the schema's own `rules()` and the bound {@see IRuleProvider}, if any, answer
 * with, each validated as written ({@see ValidSyntax}) and then expanded
 * together into one {@see ExpandedSyntax}, the schema's first. The provider may
 * answer with rules for any schema, of which only this schema's are kept; the
 * schema's own rules must all be for it.
 *
 * The guard is fixed to one (schema, user), so the rules are resolved once:
 * every check, filter, diagnosis, and reachability query on this instance reads
 * the same expanded syntax.
 */
trait ResolvesRuleSets
{
    private ?ExpandedSyntax $expandedRuleSet = null;

    /**
     * This guard's rules, validated and expanded — rules alone, every ability
     * block opened up, every include replaced by its template's rules and every
     * derived condition by its expression — memoized for the instance.
     */
    public function expandedRuleSet(): ExpandedSyntax
    {
        if ($this->expandedRuleSet !== null) {
            return $this->expandedRuleSet;
        }

        $schemaKey = $this->schema::schemaKey();

        $context = new RuleProviderContext(
            schemaKey: $schemaKey,
            schema: $this->schema::class,
            user: $this->user,
            model: $this->schema::model !== '' ? $this->schema::model : null,
        );

        $answers = $this->validated($this->schema->rules($context), SyntaxProducer::schemaRules($schemaKey, $this->schema::class));

        if (app()->bound(IRuleProvider::class)) {
            $provider = app(IRuleProvider::class);

            array_push($answers, ...$this->validated($provider->rules($context), SyntaxProducer::ruleProvider($schemaKey, $provider::class)));
        }

        return $this->expandedRuleSet = ExpandedSyntax::expand($schemaKey, new ValidationInput(new RegisteredSchemas), ...$answers);
    }

    /**
     * The rules $producer answered with, each validated as written. They may
     * come in any form: a {@see RuleSetNode}, a rule entry, a
     * {@see WarrantRuleBuilder}, rule text as a string, {@see BoundSyntax} or
     * {@see WarrantSyntax}, or an iterable of any of these. Text is parsed with
     * $producer. A builder is resolved in a source of its own. Anything
     * else is a syntax rooted at the source it was made in, the node's own, and
     * $producer is set on that source, so every node made in it says what
     * handed it over. A schema's own rules must all be for that
     * schema.
     *
     * @return list<ValidSyntax>
     */
    private function validated(mixed $rules, SyntaxProducer $producer): array
    {
        if (is_iterable($rules) && ! $rules instanceof WarrantSyntax) {
            $answers = [];

            foreach ($rules as $rule) {
                array_push($answers, ...$this->validated($rule, $producer));
            }

            return $answers;
        }

        if ($rules instanceof WarrantRuleBuilder) {
            $rules = $rules->toRule();
        }

        if ($rules instanceof IRuleEntryNode || $rules instanceof RuleSetNode) {
            $rules = new WarrantSyntax([$rules], $rules->location->source);
        }

        if ($rules instanceof WarrantSyntax) {
            $rules->source->setProducer($producer);
        }

        $syntax = match (true) {
            $rules instanceof WarrantSyntax => $rules,
            is_string($rules), $rules instanceof BoundSyntax => WarrantParser::parse($rules, $producer),
            default => throw new InvalidArgumentException(sprintf(
                '%s returned %s; expected a rule set, a rule entry, a rule builder, rule text, or an iterable of them.',
                $producer->describe(),
                get_debug_type($rules),
            )),
        };

        /* A schema's own rules are all about it. The names in another schema's
           rule set would otherwise be caught only when one happens to be missing
           from this schema, and silently left out when both share the
           vocabulary. */
        if ($producer->kind === SyntaxProducerKind::SchemaRules) {
            foreach ($syntax->isSchemaScoped() ? $syntax->schemaKeys() : [] as $named) {
                if ($named !== $producer->schemaKey) {
                    throw new InvalidArgumentException(sprintf(
                        '%s returned a rule set for schema [%s]; a schema\'s own rules are all for it.',
                        $producer->describe(),
                        $named,
                    ));
                }
            }
        }

        return [ValidSyntax::of($syntax, new ValidationInput(new RegisteredSchemas))];
    }
}
