<?php

namespace Warrant\DSL\Parsing\Validation;

use Closure;
use InvalidArgumentException;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\Positions\SourcePosition;
use Warrant\DSL\SchemaCatalog;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Registry\RegisteredSchemas;

/**
 * Validates one text as its author wrote it, against the schema each part of it
 * belongs to, with every error pointing into that text.
 *
 * A `for <schema>` body belongs to the schema its header names. Rules or a
 * condition with no header belong to whatever schema the text is used for,
 * which only its user knows: the guard reading a provider's rules, or the
 * expander reading a template's. Given that schema, they are checked against
 * it; without one, there is nothing to look their names up in, and they are
 * held only to the rules of the language that need no vocabulary.
 *
 * A text is validated on its own, before anything splits it up or combines it
 * with other rules, so every error is about the text it points into. It is read
 * as written, every binding still a placeholder: a value passed in for one is
 * not part of the text, and is not validated as if it were.
 */
final class WarrantSyntaxValidator
{
    /**
     * @param SchemaVocabulary|null $headerlessSchema The schema rules or a
     *   condition with no `for` header belong to, when the caller knows it.
     * @param string|null $headerlessSchemaKey That schema's key.
     * @param SchemaCatalog $catalog Where the schema each `for` header names is
     *   found, and the schemas cross-schema references name.
     * @param bool $headerlessRulesFormARuleSet Whether rules with no `for` header
     *   are placed in a rule set for the headerless schema as they are, as a
     *   provider's are, and so must each name their abilities. Otherwise they
     *   need only agree with one another, as a template's body may leave every
     *   one to the `@include` that places it.
     */
    public function __construct(
        private readonly ?SchemaVocabulary $headerlessSchema = null,
        private readonly ?string $headerlessSchemaKey = null,
        private readonly SchemaCatalog $catalog = new RegisteredSchemas,
        private readonly bool $headerlessRulesFormARuleSet = false,
    ) {
    }

    /** Called with each error instead of throwing it, while diagnosing. */
    private ?Closure $report = null;

    /**
     * Every rule of the language $syntax breaks, as written, each over the text
     * it is about: what {@see validateWritten()} would throw first, and every
     * error after it. A `for` header naming no schema is reported, and the body
     * under it gets no vocabulary checks.
     *
     * @return list<Diagnostic>
     */
    public function diagnose(WarrantSyntax $syntax): array
    {
        $found = [];

        $diagnosing = clone $this;
        $diagnosing->report = static function (InvalidArgumentException $error) use (&$found): void {
            $found[] = $error;
        };
        $diagnosing->validateWritten($syntax);

        /* Every node of a parsed text was recorded, so every error has a span;
           the start of the text stands in should one ever not. */
        return array_map(static fn (InvalidArgumentException $error): Diagnostic => new Diagnostic(
            $error instanceof RuleValidationException ? $error->reason : $error->getMessage(),
            $error instanceof RuleValidationException ? ($error->span?->offset ?? 0) : 0,
            $error instanceof RuleValidationException ? ($error->span?->endOffset ?? 0) : 0,
        ), $found);
    }

    /**
     * Validate $syntax as written: every rule, condition and ability name in it,
     * against the schema each part belongs to.
     */
    public function validateWritten(WarrantSyntax $syntax): void
    {
        $headerless = [];

        foreach ($syntax->written as $child) {
            match (true) {
                $child instanceof RuleSetNode => $this->validatorFor($syntax, $child)->validateWritten($child),
                $child instanceof SchemaConditionNode => $this->validatorFor($syntax, $child)->validateCondition($child),
                $child instanceof IRuleEntryNode => $headerless[] = $child,
                $child instanceof IBooleanExpressionNode => $this->headerlessValidator($syntax)->validateCondition(
                    new SchemaConditionNode((string) $this->headerlessSchemaKey, $child),
                ),
                default => null,
            };
        }

        if ($headerless !== []) {
            $this->headerlessValidator($syntax)->validateEntries(
                $headerless,
                $this->headerlessRulesFormARuleSet ? $this->headerlessSchemaKey : null,
            );
        }
    }

    /**
     * Validate $syntax as written, and expand every `for <schema>` rule set in
     * it, which validates the rules its templates answer with and the
     * expressions its derived conditions answer with, each as its own text.
     */
    public function validate(WarrantSyntax $syntax): void
    {
        $this->validateWritten($syntax);

        foreach ($syntax->children as $child) {
            if ($child instanceof RuleSetNode) {
                (new RuleSetExpander($this->catalog))->expand($child, $this->schemaOf($syntax, $child));
            }
        }
    }

    /**
     * The validator for rules with no `for` header: against the headerless
     * schema when there is one, and otherwise held only to what needs no
     * vocabulary.
     */
    private function headerlessValidator(WarrantSyntax $syntax): RuleSetValidator
    {
        $known = $this->headerlessSchema !== null && $this->headerlessSchemaKey !== null;

        return $this->reporting(new RuleSetValidator(
            $known ? $this->headerlessSchema : null,
            $known ? $this->headerlessSchemaKey : null,
            $syntax,
            catalog: $this->catalog,
        ));
    }

    /**
     * The validator for $body, against the schema its header names. When it
     * names none, which is reported, the body is still held to the rules of the
     * language that need no vocabulary.
     */
    private function validatorFor(WarrantSyntax $syntax, ISchemaScopedNode $body): RuleSetValidator
    {
        $schema = $this->schemaOf($syntax, $body);

        return $this->reporting(new RuleSetValidator(
            $schema,
            $schema === null ? null : $body->schemaKey(),
            $syntax,
            catalog: $this->catalog,
        ));
    }

    private function reporting(RuleSetValidator $validator): RuleSetValidator
    {
        return $this->report === null ? $validator : $validator->reportingTo($this->report);
    }

    /**
     * The schema $body's `for` header names: the headerless schema when it is
     * the one named, otherwise the one the catalog has under that key. A header
     * naming no schema is an error, thrown, or reported while diagnosing.
     */
    private function schemaOf(WarrantSyntax $syntax, ISchemaScopedNode $body): ?SchemaVocabulary
    {
        if ($this->headerlessSchema !== null && $body->schemaKey() === $this->headerlessSchemaKey) {
            return $this->headerlessSchema;
        }

        $schema = $this->catalog->find($body->schemaKey());

        if ($schema === null) {
            $reason = sprintf('The `for` header names unknown schema [%s].', $body->schemaKey());
            $span = $syntax->positions?->spanOf($body, ISchemaScopedNode::PART_SCHEMA_KEY);

            $error = $span === null || $syntax->source === null
                ? new InvalidArgumentException($reason)
                : new RuleValidationException($reason, SourcePosition::at($syntax->source, $span->offset), span: $span);

            if ($this->report === null) {
                throw $error;
            }

            ($this->report)($error);

            return null;
        }

        return $schema;
    }
}
