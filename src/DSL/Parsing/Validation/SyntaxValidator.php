<?php

namespace Warrant\DSL\Parsing\Validation;

use InvalidArgumentException;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BindingResolver;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SourcePosition;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Registry\RegisteredSchemas;

/**
 * Finds every mistake in one text as its author wrote it, against the schema
 * each part of it belongs to, with every mistake pointing into that text.
 *
 * A `for <schema>` body belongs to the schema its header names. Rules or a
 * condition with no header belong to whatever schema the text is used for,
 * which only its user knows and says in the {@see ValidationInput}: the guard
 * reading a provider's rules, or the expander reading a template's. Given that
 * schema, they are checked against it; without one, there is nothing to look
 * their names up in, and they are held only to the rules of the language that
 * need no vocabulary.
 *
 * A text is validated on its own, before anything splits it up or combines it
 * with other rules, so every mistake is about the text it points into. It is
 * read as written, every binding still a placeholder: a value passed in for one
 * is not part of the text, and is not validated as if it were. Whether the text
 * is the kind of answer its user asked for is the user's question, not this one.
 *
 * It throws nothing: what to do with the mistakes is
 * {@see \Warrant\DSL\Parsing\ASTNodes\ValidSyntax}'s to decide.
 *
 * @internal
 */
final class SyntaxValidator
{
    /** @var list<InvalidArgumentException> The mistakes found so far, in the order found. */
    private array $found = [];

    /**
     * @param ValidationInput $input What the text is validated against.
     */
    public function __construct(
        private readonly ValidationInput $input,
    ) {
    }

    /**
     * Every rule of the language $syntax breaks, as written, each placed over the
     * text it is about where the text was parsed, in the order found. A `for`
     * header naming no schema is one, and the body under it gets no vocabulary
     * checks.
     *
     * @return list<InvalidArgumentException>
     */
    public function mistakes(WarrantSyntax $syntax): array
    {
        $this->found = [];

        $this->assertBindingsDoNotMix($syntax);

        $headerless = [];

        foreach ($syntax->written as $child) {
            match (true) {
                $child instanceof RuleSetNode => $this->validatorFor($syntax, $child)->validateWritten($child),
                $child instanceof SchemaConditionNode => $this->validatorFor($syntax, $child)->validateCondition($child),
                $child instanceof IRuleEntryNode => $headerless[] = $child,
                $child instanceof IBooleanExpressionNode => $this->headerlessValidator($syntax)->validateCondition(
                    new SchemaConditionNode((string) $this->input->schema, $child),
                ),
                default => null,
            };
        }

        if ($headerless !== []) {
            $this->headerlessValidator($syntax)->validateEntries($headerless, $this->input->genericMode);
        }

        return $this->found;
    }

    /**
     * The validator for rules with no `for` header: against the input's schema
     * when the catalog holds it, and otherwise held only to what needs no
     * vocabulary.
     */
    private function headerlessValidator(WarrantSyntax $syntax): RuleSetValidator
    {
        $schema = $this->input->schema === null ? null : $this->input->catalog?->find($this->input->schema);

        return $this->ruleSetValidator($syntax, $schema, $schema === null ? null : $this->input->schema);
    }

    /**
     * The validator for $body, against the schema its header names. When it
     * names none, which is a mistake, the body is still held to the rules of the
     * language that need no vocabulary.
     */
    private function validatorFor(WarrantSyntax $syntax, ISchemaScopedNode $body): RuleSetValidator
    {
        $schema = $this->schemaOf($syntax, $body);

        return $this->ruleSetValidator($syntax, $schema, $schema === null ? null : $body->schemaKey());
    }

    private function ruleSetValidator(WarrantSyntax $syntax, ?SchemaVocabulary $schema, ?string $schemaKey): RuleSetValidator
    {
        return new RuleSetValidator(
            fn (InvalidArgumentException $error) => $this->found[] = $error,
            $schema,
            $schemaKey,
            $syntax,
            $this->input->callerColumnRefs,
            $this->input->catalog ?? new RegisteredSchemas,
        );
    }

    /**
     * The schema $body's `for` header names, as the catalog has it. A header
     * naming no schema the catalog holds is a mistake.
     */
    private function schemaOf(WarrantSyntax $syntax, ISchemaScopedNode $body): ?SchemaVocabulary
    {
        // A header with no name was reported where it was read; there is no schema to look up.
        if ($body->schemaKey() === INode::MISSING_NAME || $this->input->catalog === null) {
            return null;
        }

        $schema = $this->input->catalog->find($body->schemaKey());

        if ($schema === null) {
            $this->fail(
                DiagnosticCode::UnknownSchema,
                sprintf('The `for` header names unknown schema [%s].', $body->schemaKey()),
                $syntax,
                $body,
                ISchemaScopedNode::PART_SCHEMA_KEY,
            );
        }

        return $schema;
    }

    /**
     * A text's bindings are either all named or all positional, since its
     * values are given one way or the other: the first binding written decides,
     * and each of the other kind is a mistake. Binding resolution makes the same
     * check, for a syntax that never passed through here.
     */
    private function assertBindingsDoNotMix(WarrantSyntax $syntax): void
    {
        if ($syntax->source === null || $syntax->positions === null) {
            return;
        }

        $written = new WarrantSyntax($syntax->written, $syntax->source, $syntax->positions);
        $first = null;

        foreach ((new BindingResolver($syntax->source, $syntax->positions))->bindingsInOrder($written) as $binding) {
            $first ??= $binding;

            if ($binding->isPositional() !== $first->isPositional()) {
                $this->fail(DiagnosticCode::MixedBindings, 'Cannot mix named and positional bindings.', $syntax, $binding);
            }
        }
    }

    /**
     * Record $reason about $node, or its $part, where $syntax has it written.
     *
     * @param DiagnosticCode $code Which kind of mistake it is.
     * @param string $reason What is wrong, in words for the author.
     * @param WarrantSyntax $syntax The text the mistake is in.
     * @param object $node The node the mistake is about.
     * @param string|null $part The part of $node it is about; null for all of it.
     */
    private function fail(DiagnosticCode $code, string $reason, WarrantSyntax $syntax, object $node, ?string $part = null): void
    {
        $span = $syntax->positions?->spanOf($node, $part);

        $this->found[] = $span === null || $syntax->source === null
            ? new RuleValidationException($code, $reason)
            : new RuleValidationException($code, $reason, SourcePosition::at($syntax->source, $span->offset), span: $span);
    }
}
