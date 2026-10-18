<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;

/**
 * What produced a block of rule syntax: what answered with it, and for what.
 * Only what parses the syntax knows this, so the code that writes rule syntax
 * never says it; the backend fills it in as it reads the syntax.
 *
 * Made only through a constructor for its {@see SyntaxProducerKind}, so each
 * kind carries exactly the details that identify it.
 */
final readonly class SyntaxProducer
{
    /**
     * @param string|null $schemaClass The schema whose method answered; null for
     *   the kinds no schema answers for.
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
        public ?string $schemaClass = null,
        public ?string $key = null,
        public ?string $methodName = null,
        public ?string $providerClass = null,
        public ?string $path = null,
        public ConditionNode|IncludeInvocationNode|null $invokedBy = null,
    ) {}

    public static function schemaRules(string $schemaClass): self
    {
        return new self(SyntaxProducerKind::SchemaRules, schemaClass: $schemaClass);
    }

    public static function ruleProvider(string $providerClass): self
    {
        return new self(SyntaxProducerKind::RuleProvider, providerClass: $providerClass);
    }

    public static function ruleTemplate(
        string $schemaClass,
        string $templateKey,
        string $methodName,
        IncludeInvocationNode $invokedBy,
    ): self {
        return new self(SyntaxProducerKind::RuleTemplate, $schemaClass, $templateKey, $methodName, invokedBy: $invokedBy);
    }

    public static function derivedCondition(
        string $schemaClass,
        string $conditionKey,
        string $methodName,
        ConditionNode $invokedBy,
    ): self {
        return new self(SyntaxProducerKind::DerivedCondition, $schemaClass, $conditionKey, $methodName, invokedBy: $invokedBy);
    }

    public static function rawCondition(): self
    {
        return new self(SyntaxProducerKind::RawCondition);
    }

    public static function code(): self
    {
        return new self(SyntaxProducerKind::Code);
    }

    public static function editor(string $path): self
    {
        return new self(SyntaxProducerKind::Editor, path: $path);
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
            SyntaxProducerKind::Code => 'Rule text',
            SyntaxProducerKind::Editor => sprintf('The rule text in [%s]', $this->path),
        };
    }
}
