<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\Writing\RuleSyntaxWriter;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\SyntaxValidator;
use Warrant\DSL\Validation\ValidationInput;

/**
 * A syntax that has passed validation as written, under the
 * {@see ValidationInput} it holds. Validity is relative to that input: the same
 * text can be valid with no schema and invalid against one, so a syntax is valid
 * only for the input it was validated under.
 *
 * Only {@see of()} and {@see check()} make one, so holding a ValidSyntax is
 * proof that its text was validated. Its accessors hand out its nodes, each
 * holding the syntax to the shape it asks for.
 *
 * @internal
 */
final readonly class ValidSyntax extends WarrantSyntax
{
    private function __construct(WarrantSyntax $syntax, public ValidationInput $input)
    {
        parent::__construct($syntax->children, $syntax->source);
    }

    /**
     * $syntax, validated as written under $input. Throws the first mistake.
     */
    public static function of(WarrantSyntax $syntax, ValidationInput $input): self
    {
        if ($syntax instanceof self && $syntax->input == $input) {
            return $syntax;
        }

        SyntaxValidator::assertValid($syntax, $input);

        return new self($syntax, $input);
    }

    /**
     * $syntax validated as written under $input: valid, or invalid with every
     * mistake it makes. Throws nothing.
     */
    public static function check(WarrantSyntax $syntax, ValidationInput $input): self|InvalidSyntax
    {
        if ($syntax instanceof self && $syntax->input == $input) {
            return $syntax;
        }

        $mistakes = SyntaxValidator::mistakes($syntax, $input);

        if ($mistakes === []) {
            return new self($syntax, $input);
        }

        /* Every node of a parsed text is placed where it was written, so every
           mistake has a span; the start of the text stands in should one ever not. */
        return new InvalidSyntax($syntax, $input, array_map(static fn (InvalidArgumentException $error): Diagnostic => new Diagnostic(
            $error instanceof RuleValidationException ? $error->reason : $error->getMessage(),
            $error instanceof RuleValidationException ? ($error->span?->offset ?? 0) : 0,
            $error instanceof RuleValidationException ? ($error->span?->endOffset ?? 0) : 0,
            $error instanceof RuleValidationException ? $error->diagnosticCode : DiagnosticCode::InvalidRule,
        ), $mistakes));
    }

    /**
     * The condition this source holds: a bare expression, or the one expression
     * under a single `for <schema>` header, which scopes the names without
     * changing the tree.
     */
    public function conditionExpression(): IBooleanExpressionNode
    {
        if (! $this->isExpression() && ! $this->isSchemaCondition()) {
            throw $this->shapeError('a condition expression');
        }

        $child = $this->children[0];

        return $child instanceof SchemaConditionNode ? $child->expression : $child;
    }

    /**
     * The one unscoped rule this source holds.
     */
    public function rule(): WarrantRuleNode
    {
        if (! $this->isSingleRule()) {
            throw $this->shapeError('a single rule');
        }

        return $this->children[0];
    }

    /**
     * The unscoped entries. An empty source answers an empty list, since no rules
     * is a valid rule body.
     *
     * @return list<IRuleEntryNode>
     */
    public function ruleEntries(): array
    {
        if (! $this->isEmpty() && ! $this->isRuleEntries()) {
            throw $this->shapeError('unscoped rules');
        }

        /** @var list<IRuleEntryNode> */
        return $this->children;
    }

    /**
     * The one `for <schema>` rule set this source holds.
     */
    public function ruleSet(): RuleSetNode
    {
        if (! $this->isSingleRuleSet()) {
            throw $this->shapeError('a single `for <schema>` rule set');
        }

        return $this->children[0];
    }

    /**
     * Every `for <schema>` rule set, in source order and unmerged. An empty
     * source answers an empty list.
     *
     * @return list<RuleSetNode>
     */
    public function ruleSets(): array
    {
        if (! $this->isEmpty() && ! $this->isRuleSets()) {
            throw $this->shapeError('`for <schema>` rule sets');
        }

        /** @var list<RuleSetNode> */
        return $this->children;
    }

    /**
     * Every `for <schema>` body, rule sets and conditions alike, in source
     * order. An empty source answers an empty list.
     *
     * @return list<ISchemaScopedNode>
     */
    public function scoped(): array
    {
        if (! $this->isEmpty() && ! $this->isSchemaScoped()) {
            throw $this->shapeError('`for <schema>` bodies');
        }

        /** @var list<ISchemaScopedNode> */
        return $this->children;
    }

    /**
     * Every rule set for $schemaKey folded into one, in source order, or null
     * when the source never names that schema. The folded set was written
     * nowhere, and comes from where this syntax did; its entries keep where each
     * was written.
     */
    public function forSchema(string $schemaKey): ?RuleSetNode
    {
        $matching = array_values(array_filter(
            $this->ruleSets(),
            static fn (RuleSetNode $ruleSet): bool => $ruleSet->schemaKey() === $schemaKey,
        ));

        if ($matching === []) {
            return null;
        }

        $entries = [];

        foreach ($matching as $ruleSet) {
            array_push($entries, ...$ruleSet->entries);
        }

        return new RuleSetNode($matching[0]->schemaKey, $entries, SyntaxLocation::builtIn($this->source));
    }

    /**
     * This source as one rule set for $schemaKey. Unscoped entries, or no
     * entries at all, are placed in a rule set for that schema, which was
     * written nowhere but comes from where this syntax did; a single
     * `for <schema>` rule set must already name it. A header and a schema that
     * disagree is an error, never a silent choice.
     */
    public function scopedTo(string $schemaKey): RuleSetNode
    {
        if ($this->isEmpty() || $this->isRuleEntries()) {
            return new RuleSetNode(
                new NameNode($schemaKey, SyntaxLocation::builtIn($this->source)),
                $this->ruleEntries(),
                SyntaxLocation::builtIn($this->source),
            );
        }

        $ruleSet = $this->ruleSet();

        if ($ruleSet->schemaKey() !== $schemaKey) {
            throw new InvalidArgumentException(sprintf(
                'The rule text targets schema [%s] in its `for` header but was scoped to [%s].',
                $ruleSet->schemaKey(),
                $schemaKey,
            ));
        }

        return $ruleSet;
    }

    /**
     * Render the tree back to rule text with scalar values inlined as literals.
     * Throws if a value has no inline representation — use
     * {@see toBoundSyntax()} for those.
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::toSyntax($this);
    }

    /**
     * Render the tree to `?`-parameterized rule text plus one flat, left-to-right
     * positional bindings list. Lossless for any value.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::toBoundSyntax($this);
    }
}
