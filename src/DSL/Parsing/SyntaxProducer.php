<?php

namespace Warrant\DSL\Parsing;

use InvalidArgumentException;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Validation\GenericMode;

/**
 * What produced a block of rule syntax: what answered with it, and for what.
 * Only what parses the syntax knows this, so the code that writes rule syntax
 * never says it; the backend fills it in as it reads the syntax.
 *
 * Made only through a constructor for its {@see SyntaxProducerKind}, so each
 * kind carries exactly the details that identify it. What produced the syntax
 * also says what it is validated as: the schema its rules with no `for` header
 * belong to, whether they name their abilities, and where the names in it are
 * looked up.
 */
final readonly class SyntaxProducer
{
    /**
     * @param string|null $schemaKey The key of the schema the syntax is for: the
     *   one its rules or condition with no `for` header belong to. For a
     *   template, that is the schema whose `@include` placed it, whose abilities
     *   its rules take. For a derived condition, it is the schema whose rules
     *   reached it. Null when no schema is known.
     * @param string|null $schemaClass The schema whose method answered, which
     *   decides where the names in the syntax are looked up: in that schema and
     *   then the global schemas, or, when it is a global schema, in the global
     *   schemas alone. It is the class registered under $schemaKey unless a
     *   global schema answered, since a global has no key. Null for the kinds no
     *   schema answers for.
     * @param string|null $key The template or derived condition key.
     * @param string|null $methodName The schema method that answered.
     * @param string|null $providerClass The rule provider that answered.
     * @param string|null $path The document the language server read.
     * @param ConditionNode|IncludeInvocationNode|null $invokedBy The `@include`
     *   or condition whose answer the syntax is, with the arguments it passed
     *   in; null for the kinds nothing invokes.
     */
    private function __construct(
        public SyntaxProducerKind $kind,
        public ?string $schemaKey = null,
        public ?string $schemaClass = null,
        public ?string $key = null,
        public ?string $methodName = null,
        public ?string $providerClass = null,
        public ?string $path = null,
        public ConditionNode|IncludeInvocationNode|null $invokedBy = null,
    ) {}

    public static function schemaRules(string $schemaKey, string $schemaClass): self
    {
        return new self(SyntaxProducerKind::SchemaRules, $schemaKey, schemaClass: $schemaClass);
    }

    /**
     * @param string $schemaKey The schema the provider answered with rules for.
     */
    public static function ruleProvider(string $schemaKey, string $providerClass): self
    {
        return new self(SyntaxProducerKind::RuleProvider, $schemaKey, providerClass: $providerClass);
    }

    public static function ruleTemplate(
        string $schemaKey,
        string $schemaClass,
        string $templateKey,
        string $methodName,
        IncludeInvocationNode $invokedBy,
    ): self {
        return new self(
            SyntaxProducerKind::RuleTemplate,
            $schemaKey,
            $schemaClass,
            $templateKey,
            $methodName,
            invokedBy: $invokedBy,
        );
    }

    public static function derivedCondition(
        string $schemaKey,
        string $schemaClass,
        string $conditionKey,
        string $methodName,
        ConditionNode $invokedBy,
    ): self {
        return new self(
            SyntaxProducerKind::DerivedCondition,
            $schemaKey,
            $schemaClass,
            $conditionKey,
            $methodName,
            invokedBy: $invokedBy,
        );
    }

    /**
     * Text a template or derived condition answers with, read by the language
     * server from the method that answers with it, where nothing has invoked it.
     *
     * @param SyntaxProducerKind $kind {@see SyntaxProducerKind::RuleTemplate} or
     *   {@see SyntaxProducerKind::DerivedCondition}.
     * @param string $path The document the language server read.
     * @param string|null $schemaKey The key of the schema the method is on; null
     *   for a global schema, which has none, or a schema not known.
     * @param string $schemaClass The method's class.
     * @param string $methodName The method.
     */
    public static function editorAnswer(
        SyntaxProducerKind $kind,
        string $path,
        ?string $schemaKey,
        string $schemaClass,
        string $methodName,
    ): self {
        if (! in_array($kind, [SyntaxProducerKind::RuleTemplate, SyntaxProducerKind::DerivedCondition], true)) {
            throw new InvalidArgumentException(sprintf('Only a template or derived condition answers with text; got %s.', $kind->name));
        }

        return new self($kind, $schemaKey, $schemaClass, methodName: $methodName, path: $path);
    }

    public static function rawCondition(): self
    {
        return new self(SyntaxProducerKind::RawCondition);
    }

    /**
     * @param string|null $schemaKey The schema the document places the syntax
     *   in, when it says; null for none.
     */
    public static function editor(string $path, ?string $schemaKey = null): self
    {
        return new self(SyntaxProducerKind::Editor, $schemaKey, path: $path);
    }

    /**
     * The nodes the `@include` or condition that invoked this passed in: its
     * arguments, as the caller wrote them. They are the caller's, placed where
     * the caller produced them.
     *
     * @return list<\Warrant\DSL\Parsing\ASTNodes\INode>
     */
    public function callerArguments(): array
    {
        return match (true) {
            $this->invokedBy instanceof ConditionNode => $this->invokedBy->parameters,
            $this->invokedBy instanceof IncludeInvocationNode => $this->invokedBy->arguments,
            default => [],
        };
    }

    /**
     * What answered with the syntax, in words, as an error message opens with it.
     */
    public function describe(): string
    {
        return match ($this->kind) {
            SyntaxProducerKind::SchemaRules => sprintf('The rules() of schema [%s]', $this->schemaClass),
            SyntaxProducerKind::RuleProvider => 'The rule provider',
            SyntaxProducerKind::RuleTemplate => sprintf('Rule template [%s::%s]', $this->schemaClass, $this->methodName),
            SyntaxProducerKind::DerivedCondition => sprintf('Derived condition [%s::%s]', $this->schemaClass, $this->methodName),
            SyntaxProducerKind::RawCondition => 'A raw condition',
            SyntaxProducerKind::Editor => sprintf('The rule text in [%s]', $this->path),
        };
    }

    /**
     * Whether the rules this answered with name their abilities, under a `for`
     * header or not: a template's leave them to the `@include` that places them, a
     * schema's rules and a rule provider's name them, and anything else may do
     * either, so long as all of them do the same.
     */
    public function genericMode(): GenericMode
    {
        return match ($this->kind) {
            SyntaxProducerKind::RuleTemplate => GenericMode::RequireGeneric,
            SyntaxProducerKind::SchemaRules, SyntaxProducerKind::RuleProvider => GenericMode::RequireNotGeneric,
            default => GenericMode::Either,
        };
    }
}
