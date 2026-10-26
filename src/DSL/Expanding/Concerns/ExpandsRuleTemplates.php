<?php

namespace Warrant\DSL\Expanding\Concerns;

use RuntimeException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Resolution\FoundRuleTemplate;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Schema\RuleTemplates\RuleTemplateContext;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * How {@see Expander} expands an `@include`: it calls the template the
 * include names with the include's arguments, hands over what the template
 * answers with as the template's own syntax, validates it, and splices in its
 * generic rules and includes with the abilities the include names.
 *
 * @internal
 */
trait ExpandsRuleTemplates
{
    /**
     * @return list<RuleNode>
     */
    private function expandInclude(
        IncludeInvocationNode $include,
        ISchema $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array {
        $deeper = $trail->enteringInclude($include);

        $found = $this->resolveTemplate($schemaKey, $include);
        $definition = $found->definition;
        $owner = $found->schema();
        $producer = SyntaxProducer::ruleTemplate($schemaKey, $owner::class, $definition->key, $definition->methodName, $include);

        $arguments = self::argumentValues($include->arguments);

        /* A template is called once for all the include's abilities, or, declared
           callOncePerAbility, once for each of them, each call's rules taking its
           ability alone. */
        $calls = $definition->callOncePerAbility
            ? array_map(static fn (NameNode $ability): array => [$ability], $include->abilities)
            : [$include->abilities];

        $expanded = [];

        foreach ($calls as $abilities) {
            $context = $definition->takesContext
                ? new RuleTemplateContext(
                    array_map(static fn (NameNode $ability): string => $ability->name, $abilities),
                    $definition->callOncePerAbility,
                )
                : null;

            /* The context is told to the method, not to its rule text: a binding
               names a DSL argument, and the context is none. */
            $bindings = array_filter(
                self::parametersUsableAsBindings(
                    $owner,
                    $definition->methodName,
                    $context === null ? $arguments : [$context, ...$arguments],
                ),
                static fn (mixed $value): bool => ! $value instanceof RuleTemplateContext,
            );

            $generic = $this->templateEntries(
                $owner->invokeRuleTemplate($definition, $context, $arguments),
                $producer,
                $bindings,
            );

            /* The body is generic, as an ability block's is: the include names the
               abilities its can/cannot clauses take, so they are applied here. */
            $entries = array_map(
                static fn (RuleNode|IncludeInvocationNode|WhenBlockNode $entry): RuleNode|IncludeInvocationNode|WhenBlockNode
                    => $entry->withAbilities($abilities),
                $generic,
            );

            /* The body may hold includes of its own, taking the same abilities. They
               are expanded here rather than left for the caller so that what comes
               back is rules and only rules, however deep the templates went. */
            array_push($expanded, ...$this->expandEntries($entries, $schema, $schemaKey, $deeper));
        }

        return $expanded;
    }

    /**
     * The generic rules, includes and when blocks in what a rule template
     * answered with, in whichever form it built them: rule text as a string,
     * {@see BoundSyntax} or {@see WarrantSyntax},
     * a generic {@see RuleNode} or {@see IncludeInvocationNode}, a
     * {@see WarrantRuleBuilder} making a generic rule, or an iterable of any of
     * these. Only rule text may hold a when block. A string's bindings take their values from
     * $parametersUsableAsBindings, the template's parameters by name.
     *
     * Every entry must be generic. The `@include` expanding the template names the
     * abilities its can/cannot clauses take, so an entry naming its own has two answers to the
     * one question, and a rule set or ability block, which always name theirs, has
     * no place in a template at all.
     *
     * $producer, the template answering the `@include`, is set on the source
     * every answer was made in: its text's, or a built node's own, which every
     * node a builder made with it shares. A rule builder is resolved in a source
     * of its own. Each answer is validated before it is spliced in:
     * rule text as the text it is, so an error points into it, and a rule or
     * include built in code as the node it is.
     *
     * @param array<string, mixed> $parametersUsableAsBindings
     * @return list<RuleNode|IncludeInvocationNode|WhenBlockNode>
     */
    private function templateEntries(mixed $body, SyntaxProducer $producer, array $parametersUsableAsBindings): array
    {
        if ($body instanceof WarrantRuleBuilder) {
            $body = $body->toRule();
        }

        if (is_string($body)) {
            $body = self::withParameterBindings($body, $parametersUsableAsBindings);
        }

        if ($body instanceof BoundSyntax) {
            $body = WarrantParser::parse($body, $producer);
        } elseif ($body instanceof WarrantSyntax) {
            $body->source->setProducer($producer);
        }

        if ($body instanceof WarrantSyntax) {
            if (! $body->isEmpty() && ! $body->isRuleEntries()) {
                throw new RuntimeException(sprintf(
                    '%s answered with rule text that is not unscoped rules and includes; a template answers '
                        .'with rules and includes that name no abilities.',
                    $producer->describe(),
                ));
            }

            $valid = self::validateAnswer($producer, fn () => ValidSyntax::of($body, new ValidationInput($this->catalog)));

            /** @var list<RuleNode|IncludeInvocationNode|WhenBlockNode> */
            return self::eachWithBindingsReplaced($valid->ruleEntries());
        }

        if ($body instanceof RuleNode || $body instanceof IncludeInvocationNode) {
            if (! $body->isGeneric()) {
                throw new RuntimeException(sprintf(
                    '%s answered with %s that names abilities; the @include that expands a template names the '
                        .'abilities its rules and includes take.',
                    $producer->describe(),
                    $body instanceof RuleNode ? 'a rule' : 'an @include',
                ));
            }

            return $this->templateEntries(new WarrantSyntax([$body], $body->location->source), $producer, $parametersUsableAsBindings);
        }

        if ($body instanceof AbilityBlockNode || $body instanceof RuleSetNode) {
            throw new RuntimeException(sprintf(
                '%s answered with %s, which names abilities; the @include that expands a template names the '
                    .'abilities its rules and includes take.',
                $producer->describe(),
                $body instanceof AbilityBlockNode ? 'an ability block' : 'a rule set',
            ));
        }

        if (is_iterable($body)) {
            $entries = [];

            foreach ($body as $item) {
                array_push($entries, ...$this->templateEntries($item, $producer, $parametersUsableAsBindings));
            }

            return $entries;
        }

        throw new RuntimeException(sprintf(
            '%s must answer with rule text, a %s, a rule, rule builder or @include that names no abilities, or an '
                .'iterable of them; got %s.',
            $producer->describe(),
            WarrantSyntax::class,
            get_debug_type($body),
        ));
    }

    /**
     * The template an include names, resolved from the schema the include was
     * written in, rejecting one no schema declares and one the include gives too
     * few arguments.
     */
    private function resolveTemplate(string $schemaKey, IncludeInvocationNode $include): FoundRuleTemplate
    {
        $found = $this->constructs->ruleTemplate(
            $include->templateKey->name,
            $this->constructs->writtenIn($include->location->source->producer(), $schemaKey),
        );

        if ($found === null) {
            throw new RuleValidationException(
                DiagnosticCode::UnknownTemplate,
                LanguageRuleMessages::unknownTemplate($schemaKey, $include->templateKey->name),
            );
        }

        if (count($include->arguments) < $found->definition->requiredArgumentCount) {
            throw new RuleValidationException(
                DiagnosticCode::TooFewArguments,
                LanguageRuleMessages::tooFewTemplateArguments(
                    $include->templateKey->name,
                    $found->definition->requiredArgumentCount,
                    count($include->arguments),
                ),
            );
        }

        return $found;
    }
}
