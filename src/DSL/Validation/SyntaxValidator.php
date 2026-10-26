<?php

namespace Warrant\DSL\Validation;

use LogicException;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SourcePosition;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxProducerKind;
use Warrant\DSL\Resolution\ConstructLookup;
use Warrant\DSL\Resolution\GlobalSchemaId;
use Warrant\Schema\Vocabulary;

/**
 * Finds every mistake in one text as its author wrote it, against the schema
 * each part of it belongs to, with every mistake pointing into that text.
 *
 * ## What this is for
 *
 * Early feedback, not safety. A name that resolves to nothing is rejected by
 * {@see \Warrant\DSL\Compiling\RuleSetCompiler} at the lookup that needs it, so
 * nothing depends on this class having run: skip it and a broken rule still fails
 * loudly, just later and against a live user and query.
 *
 * What it adds is reach in the other direction. It answers from rule text alone —
 * no database, no user, no query — which is what a language server or a CI check
 * needs, and it sees every rule in a set rather than only the paths a particular
 * check happens to compile.
 *
 * ## What each part is checked against
 *
 * A `for <schema>` body belongs to the schema its header names. Rules or a
 * condition with no header belong to the schema the text is for, which is what
 * produced it says: the {@see SyntaxProducer} of the {@see ValidationInput},
 * the one its source had when it was handed over, such as a schema's rules() or
 * one of its templates. Given that schema, they are checked against
 * it; without one, there is nothing to look their names up in, and they are
 * held only to the rules of the language that need no vocabulary. Whether they
 * name their abilities is what produced them says too: a template's leave them
 * to the `@include`, and a schema's own rules name them.
 *
 * A text is validated on its own, before anything splits it up or combines it
 * with other rules, so every mistake is about the text it points into. The rules
 * a template answers with and the expression a derived condition answers with
 * are texts of their own, validated by {@see Expander} before it splices
 * them in, so nothing a compile could reach is out of sight. Expansion reads no
 * user, row or check context, so neither does this.
 *
 * A text is read as written, every binding still a placeholder: a value passed
 * in for one is not part of the text, and is not validated as if it were. What
 * is checked of the values is only that they fit the placeholders, when the
 * {@see ValidationInput} says to: every placeholder has one, every one given is
 * used, and each can stand where its placeholder was written. Whether the text
 * is the kind of answer its user asked for is the user's question, not this one.
 *
 * ## Where an error points
 *
 * An error says where it is in the text the node it is about was read from,
 * at the name, value or can/cannot clause it is about. A node built in code was written
 * nowhere, and its error says only what is wrong.
 *
 * {@see assertValid()} validates strictly, throwing the first mistake, and
 * {@see mistakes()} tolerantly, returning every one. Both make the same walk
 * in the same order, so the mistake one throws is the first the other
 * returns.
 *
 * @internal
 */
final class SyntaxValidator
{
    use ValidatesAbilityBlocks;
    use ValidatesBindings;
    use ValidatesExpressions;
    use ValidatesRuleEntries;
    use ValidatesWhenBlocks;

    /** @var list<RuleValidationException> The mistakes found so far, in the order found, when validating tolerantly. */
    private array $found = [];

    /**
     * @param WarrantSyntax $syntax The text being validated.
     * @param ValidationInput $input What the text is validated against.
     * @param bool $tolerant Whether a mistake is noted and the walk goes on,
     *   rather than thrown.
     */
    private function __construct(
        private readonly WarrantSyntax $syntax,
        private readonly ValidationInput $input,
        private readonly bool $tolerant,
    ) {
    }

    /**
     * What the names in the text mean, resolved against the catalog. Only
     * reached with a catalog in hand: with none, no name is looked up.
     */
    private function constructs(): ConstructLookup
    {
        return ($this->input->catalog ?? throw new LogicException('No catalog to look names up in.'))->constructs();
    }

    /**
     * Throw the first rule of the language $syntax breaks, as written, placed
     * over the text it is about where the text was parsed.
     *
     * @throws RuleValidationException
     */
    public static function assertValid(WarrantSyntax $syntax, ValidationInput $input): void
    {
        (new self($syntax, $input, tolerant: false))->validate();
    }

    /**
     * Every rule of the language $syntax breaks, as written, each placed over the
     * text it is about where the text was parsed, in the order found.
     *
     * @return list<RuleValidationException>
     */
    public static function mistakes(WarrantSyntax $syntax, ValidationInput $input): array
    {
        $validator = new self($syntax, $input, tolerant: true);
        $validator->validate();

        return $validator->found;
    }

    /**
     * Report every rule of the language the syntax breaks: its bindings first,
     * then its rules and conditions against the schemas they belong to. A `for`
     * header naming no schema is one, and the body under it gets no vocabulary
     * checks.
     */
    private function validate(): void
    {
        $this->assertBindingsFit($this->syntax);

        $producer = $this->input->producer;
        $producerSchema = $producer?->schemaKey === null ? null : $this->input->catalog?->find($producer->schemaKey);
        $producerSchemaKey = $producer?->schemaKey;

        $headerless = [];

        foreach ($this->syntax->children as $child) {
            if ($child instanceof ISchemaScopedNode) {
                $this->assertHeaderFitsProducer($child);
            }

            /* A header says whose names the entries use, not who names their
               abilities: what produced the text says that, header or none. */
            match (true) {
                $child instanceof RuleSetNode => $this->validateEntries(
                    $child->entries,
                    $producer?->genericMode() ?? GenericMode::Either,
                    $this->schemaNamedBy($child),
                    $child->schemaKey(),
                ),
                $child instanceof SchemaConditionNode => $this->validateCondition($child->expression, $this->schemaNamedBy($child), $child->schemaKey()),
                $child instanceof IRuleEntryNode => $headerless[] = $child,
                $child instanceof IBooleanExpressionNode => $this->validateCondition($child, $producerSchema, $producerSchemaKey),
                default => null,
            };
        }

        if ($headerless !== []) {
            $this->validateEntries($headerless, $producer?->genericMode() ?? GenericMode::Either, $producerSchema, $producerSchemaKey);
        }
    }

    /**
     * A template's or derived condition's answer is about the schema the method
     * belongs to, so a `for` header in it may name that schema and no other. A
     * global schema's answer is about no schema: its names are looked up in the
     * global schemas, and it has no key for a header to name, so it takes no
     * header at all.
     */
    private function assertHeaderFitsProducer(ISchemaScopedNode $body): void
    {
        $producer = $this->input->producer;

        if (
            $producer === null
            || $producer->schemaKey === null
            || ! in_array($producer->kind, [SyntaxProducerKind::RuleTemplate, SyntaxProducerKind::DerivedCondition], true)
            || $body->schemaKey() === INode::MISSING_NAME
        ) {
            return;
        }

        $answeredByGlobal = $this->input->catalog !== null
            && $this->constructs()->writtenIn($producer, $producer->schemaKey) instanceof GlobalSchemaId;

        if ($answeredByGlobal) {
            $this->fail(
                DiagnosticCode::HeaderInGlobalAnswer,
                sprintf(
                    '%s answered under a `for` header naming schema [%s]; a global schema answers with no header, '
                        .'since its names are looked up in the global schemas.',
                    $producer->describe(),
                    $body->schemaKey(),
                ),
                $body->schemaKey->location,
            );

            return;
        }

        if ($body->schemaKey() !== $producer->schemaKey) {
            $this->fail(
                DiagnosticCode::HeaderNamesAnotherSchema,
                sprintf(
                    '%s answered under a `for` header naming schema [%s]; it belongs to schema [%s].',
                    $producer->describe(),
                    $body->schemaKey(),
                    $producer->schemaKey,
                ),
                $body->schemaKey->location,
            );
        }
    }

    /**
     * The schema $body's `for` header names, as the catalog has it. A header
     * naming no schema the catalog holds is a mistake.
     */
    private function schemaNamedBy(ISchemaScopedNode $body): ?Vocabulary
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
                $body->schemaKey->location,
            );
        }

        return $schema;
    }

    /**
     * Report a mistake at $at, in the text it was read from: throw it when
     * validating strictly, and note it and go on when validating tolerantly.
     * Every mistake comes through here, so strict and tolerant validation agree
     * on what one is. A mistake about something built in code has no text to
     * point into, and says only what is wrong.
     *
     * @param DiagnosticCode $code Which kind of mistake it is.
     * @param string $reason What is wrong, in words for the author.
     * @param SyntaxLocation $at Where the thing the mistake is about was written.
     */
    private function fail(DiagnosticCode $code, string $reason, SyntaxLocation $at): void
    {
        $mistake = $at->span === null
            ? new RuleValidationException($code, $reason)
            : new RuleValidationException(
                $code,
                $reason,
                SourcePosition::at($at->source->bound->syntax ?? '', $at->span->offset),
                span: $at->span,
            );

        if (! $this->tolerant) {
            throw $mistake;
        }

        $this->found[] = $mistake;
    }
}
