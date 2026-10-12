<?php

namespace Warrant\DSL\Parsing\Validation;

use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Positions\SourcePosition;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Facades\Warrant;

/**
 * Validates one text as its author wrote it, against the schema each part of it
 * belongs to, with every error pointing into that text.
 *
 * A `for <schema>` body belongs to the schema its header names. Rules or a
 * condition with no header belong to whatever schema the text is used for,
 * which only its user knows: the guard reading a provider's rules, or the
 * expander reading a template's. Given that schema, they are checked against
 * it; without one, there is nothing to look their names up in, and they are
 * not checked.
 *
 * A text is validated on its own, before anything splits it up or combines it
 * with other rules, so every error is about the text it points into.
 */
final class WarrantSyntaxValidator
{
    /**
     * @param SchemaVocabulary|null $headerlessSchema The schema rules or a
     *   condition with no `for` header belong to, when the caller knows it.
     * @param string|null $headerlessSchemaKey That schema's key.
     */
    public function __construct(
        private readonly ?SchemaVocabulary $headerlessSchema = null,
        private readonly ?string $headerlessSchemaKey = null,
    ) {
    }

    /**
     * Validate $syntax as written: every rule, condition and ability name in it,
     * against the schema each part belongs to.
     */
    public function validateWritten(WarrantSyntax $syntax): void
    {
        $headerless = [];

        foreach ($syntax->children as $child) {
            match (true) {
                $child instanceof RuleSetNode => $this->validatorFor($syntax, $child)->validateWritten($child),
                $child instanceof SchemaConditionNode => $this->validatorFor($syntax, $child)->validateCondition($child),
                $child instanceof IRuleEntryNode => $headerless[] = $child,
                $child instanceof IBooleanExpressionNode => $this->headerlessValidator($syntax)?->validateCondition(
                    new SchemaConditionNode((string) $this->headerlessSchemaKey, $child),
                ),
                default => null,
            };
        }

        if ($headerless !== []) {
            $this->headerlessValidator($syntax)?->validateEntries($headerless);
        }
    }

    /**
     * Validate $syntax as written, and every `for <schema>` rule set in it again
     * once expanded: the rules its templates supply and the expressions its
     * derived conditions answer with, as the compiler will read them.
     */
    public function validate(WarrantSyntax $syntax): void
    {
        $this->validateWritten($syntax);

        foreach ($syntax->children as $child) {
            if ($child instanceof RuleSetNode) {
                $validator = $this->validatorFor($syntax, $child);
                $validator->validateExpanded((new RuleSetExpander)->expand($child, $this->schemaOf($syntax, $child)));
            }
        }
    }

    private function headerlessValidator(WarrantSyntax $syntax): ?RuleSetValidator
    {
        return $this->headerlessSchema === null || $this->headerlessSchemaKey === null
            ? null
            : new RuleSetValidator($this->headerlessSchema, $this->headerlessSchemaKey, $syntax);
    }

    private function validatorFor(WarrantSyntax $syntax, ISchemaScopedNode $body): RuleSetValidator
    {
        return new RuleSetValidator($this->schemaOf($syntax, $body), $body->schemaKey(), $syntax);
    }

    /**
     * The schema $body's `for` header names: the headerless schema when it is
     * the one named, otherwise the one registered under that key.
     */
    private function schemaOf(WarrantSyntax $syntax, ISchemaScopedNode $body): SchemaVocabulary
    {
        if ($this->headerlessSchema !== null && $body->schemaKey() === $this->headerlessSchemaKey) {
            return $this->headerlessSchema;
        }

        try {
            $schemaClass = Warrant::registry()->resolveSchemaClassOrFail($body->schemaKey());
        } catch (OutOfBoundsException $e) {
            $reason = sprintf('The `for` header names unknown schema [%s].', $body->schemaKey());
            $span = $syntax->positions?->spanOf($body, ISchemaScopedNode::PART_SCHEMA_KEY);

            throw $span === null || $syntax->source === null
                ? new InvalidArgumentException($reason, previous: $e)
                : new RuleValidationException($reason, SourcePosition::at($syntax->source, $span->offset), $e);
        }

        return new $schemaClass;
    }
}
