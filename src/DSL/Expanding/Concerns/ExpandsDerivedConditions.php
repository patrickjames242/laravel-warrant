<?php

namespace Warrant\DSL\Expanding\Concerns;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Warrant\Builders\CrossSchemaCanBuilder;
use Warrant\Builders\CrossSchemaCheckBuilder;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Expanding\UnknownNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\DSL\Resolution\FoundCondition;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * How {@see Expander} expands a derived condition a rule names: it calls
 * the condition's method with the rule's arguments, hands over what it answers
 * with as the condition's own syntax, validates it, and wraps the expression in
 * a {@see DerivedConditionNode}.
 *
 * @internal
 */
trait ExpandsDerivedConditions
{
    /**
     * Call a derived condition with the arguments the rule gave it, and wrap the
     * expanded expression it answers with.
     */
    private function expandDerivedCondition(
        ConditionNode $node,
        FoundCondition $found,
        ISchema $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): DerivedConditionNode {
        $definition = $found->definition;
        $owner = $found->schema();
        $declaredIn = $found->declaredIn();
        $deeper = $trail->enteringDerivedCondition(
            $declaredIn instanceof KeyedSchemaId ? $declaredIn->key : $found->schemaClass(),
            $node->conditionKey->name,
        );

        if (count($node->parameters) < $definition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.',
                $node->conditionKey->name,
                $owner::class,
                $definition->requiredArgumentCount,
                count($node->parameters),
            ));
        }

        if ($definition->maxArgumentCount !== null && count($node->parameters) > $definition->maxArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] takes at most %d argument(s), but the rule supplied %d.',
                $node->conditionKey->name,
                $owner::class,
                $definition->maxArgumentCount,
                count($node->parameters),
            ));
        }

        $producer = SyntaxProducer::derivedCondition($schemaKey, $owner::class, $definition->key, $definition->methodName, $node);
        $values = self::argumentValues($node->parameters);
        $answer = $owner->invokeDerivedCondition($definition, $values);
        $parametersUsableAsBindings = self::parametersUsableAsBindings($owner, $definition->methodName, $values);

        return new DerivedConditionNode(
            $node->conditionKey,
            $node->parameters,
            $this->expandExpression(
                $this->derivedExpression($answer, $producer, $schemaKey, $parametersUsableAsBindings),
                $schema,
                $schemaKey,
                $this->catalog->constructs()->writtenIn($producer, $schemaKey),
                $deeper,
            ),
            $node->location,
        );
    }

    /**
     * What a derived condition answered with, as an expression node in the
     * source it was made in, with $producer, the condition answering the rule
     * that named it, set on that source: its text's, a built node's own, or a
     * new one that a builder is resolved in.
     *
     * Rule text, as a string, a {@see BoundSyntax} or a {@see WarrantSyntax}, must hold a condition
     * expression, bare or under a `for` header naming this schema. A string's
     * bindings take their values from $parametersUsableAsBindings, the condition's
     * parameters by name. A
     * {@see WarrantConditionBuilder} is resolved to the tree it composed, and a
     * {@see CrossSchemaCanBuilder} or {@see CrossSchemaCheckBuilder} to its
     * `can(...)` or `check(...)`. A bool
     * decides the outcome outright, and null answers unknown.
     *
     * An expression is validated before it is spliced in: rule text as the text
     * it is, so an error points into it, and an expression built in code as the
     * nodes it is.
     *
     * @param array<string, mixed> $parametersUsableAsBindings
     */
    private function derivedExpression(mixed $answer, SyntaxProducer $producer, string $schemaKey, array $parametersUsableAsBindings): IBooleanExpressionNode
    {
        if (is_string($answer)) {
            $answer = self::withParameterBindings($answer, $parametersUsableAsBindings);
        }

        if ($answer instanceof BoundSyntax || $answer instanceof WarrantSyntax) {
            return $this->derivedSyntaxExpression($answer, $producer);
        }

        /* A constant built here is held in a source of its own, so it keeps
           no producer. */
        $source = $answer instanceof IBooleanExpressionNode ? $answer->location->source : new SyntaxSource(null);
        $expression = $this->derivedNodeExpression($answer, $producer, $source);
        $source->setProducer($producer);

        $valid = self::validateAnswer(
            $producer,
            fn () => ValidSyntax::of(
                new WarrantSyntax([$expression], $source),
                new ValidationInput($this->catalog),
            ),
        );

        return self::withBindingsReplaced($valid->conditionExpression());
    }

    /**
     * What a derived condition answered with, when it answered with anything but
     * rule text. A builder is resolved in $source. A constant it answered with
     * is a node built here, with no text and no producer.
     */
    private function derivedNodeExpression(mixed $answer, SyntaxProducer $producer, SyntaxSource $source): IBooleanExpressionNode
    {
        if ($answer instanceof CrossSchemaCanBuilder || $answer instanceof CrossSchemaCheckBuilder) {
            return $answer->toNode($source);
        }

        if ($answer instanceof WarrantConditionBuilder) {
            /* A builder with no terms is the structural twin of a condition that
               added no where clause: it would mean "match everything", which is
               almost always a forgotten branch rather than an intent. */
            return $answer->buildConditions($source) ?? throw new InvalidArgumentException(sprintf(
                '%s returned a condition builder with no terms, which would silently match every row; add at '
                    .'least one term, return true/false to decide the outcome outright, or return null to '
                    .'answer unknown.',
                $producer->describe(),
            ));
        }

        return match (true) {
            $answer instanceof IBooleanExpressionNode => $answer,
            is_bool($answer) => new BooleanNode($answer, SyntaxLocation::noProducer()),
            $answer === null => new UnknownNode(SyntaxLocation::noProducer()),
            default => throw new RuntimeException(sprintf(
                '%s must answer with an expression, a %s, a %s, a %s, rule text, a %s, a bool or null; got %s.',
                $producer->describe(),
                WarrantConditionBuilder::class,
                CrossSchemaCanBuilder::class,
                CrossSchemaCheckBuilder::class,
                WarrantSyntax::class,
                get_debug_type($answer),
            )),
        };
    }

    /**
     * The condition expression in the rule text a derived condition answered with.
     *
     * Text that does not parse, or parses to something other than an expression,
     * is the condition's mistake, but the parser can only point at a position in
     * the text. Naming the method that answered with it is what makes it findable.
     *
     * A `for` header in it may name only the schema the condition belongs to,
     * and none at all in a global schema's; validation holds it to that.
     */
    private function derivedSyntaxExpression(BoundSyntax|WarrantSyntax $text, SyntaxProducer $producer): IBooleanExpressionNode
    {
        try {
            if (! $text instanceof WarrantSyntax) {
                $syntax = WarrantParser::parse($text, $producer);
            } else {
                $syntax = $text;
                $syntax->source->setProducer($producer);
            }

            /* Its shape alone, asked of the tree as written: no value is put in
               place until the text has been validated. */
            if (! $syntax->isExpression() && ! $syntax->isSchemaCondition()) {
                throw new LogicException(sprintf('Expected a condition expression, but the source holds %s.', $syntax->describe()));
            }
        } catch (WarrantSyntaxException|LogicException $e) {
            throw new RuntimeException(sprintf(
                "%s answered with rule text that is not a condition expression:\n\n%s",
                $producer->describe(),
                $e->getMessage(),
            ), previous: $e);
        }

        $valid = self::validateAnswer($producer, fn () => ValidSyntax::of($syntax, new ValidationInput($this->catalog)));

        return self::withBindingsReplaced($valid->conditionExpression());
    }
}
