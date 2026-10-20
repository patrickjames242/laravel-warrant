<?php

namespace Warrant\DSL\Expanding\Concerns;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Expanding\Expander;
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
use Warrant\DSL\SchemaVocabulary;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Schema\ConditionDefinition;

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
        ConditionDefinition $definition,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): DerivedConditionNode {
        $deeper = $trail->enteringDerivedCondition($schemaKey, $node->conditionKey->name);

        if (count($node->parameters) < $definition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.',
                $node->conditionKey->name,
                $schema::class,
                $definition->requiredArgumentCount,
                count($node->parameters),
            ));
        }

        $producer = SyntaxProducer::derivedCondition($schemaKey, $schema::class, $definition->key, $definition->methodName, $node);
        $answer = $schema->{$definition->methodName}(...self::argumentValues($node->parameters));

        return new DerivedConditionNode(
            $node->conditionKey,
            $node->parameters,
            $this->expandExpression($this->derivedExpression($answer, $producer, $schemaKey), $schema, $schemaKey, $deeper),
            $node->location,
        );
    }

    /**
     * What a derived condition answered with, as an expression node in the
     * source it was made in, with $producer, the condition answering the rule
     * that named it, set on that source: its text's, the builder's, or a built
     * node's own.
     *
     * Rule text, as a string, a {@see BoundSyntax} or a {@see WarrantSyntax}, must hold a condition
     * expression, bare or under a `for` header naming this schema. A
     * {@see WarrantConditionBuilder} is unwrapped to the tree it composed. A bool
     * decides the outcome outright, and null answers unknown.
     *
     * An expression is validated before it is spliced in: rule text as the text
     * it is, so an error points into it, and an expression built in code as the
     * nodes it is.
     */
    private function derivedExpression(mixed $answer, SyntaxProducer $producer, string $schemaKey): IBooleanExpressionNode
    {
        if (is_string($answer) || $answer instanceof BoundSyntax || $answer instanceof WarrantSyntax) {
            return $this->derivedSyntaxExpression($answer, $producer, $schemaKey);
        }

        $expression = $this->derivedNodeExpression($answer, $producer);

        /* A constant built here is held in a source of its own, so it keeps
           no producer. */
        $source = match (true) {
            $answer instanceof WarrantConditionBuilder => $answer->source,
            $answer instanceof IBooleanExpressionNode => $answer->location->source,
            default => new SyntaxSource(null),
        };
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
     * rule text. A constant it answered with is a node built here, with no
     * text and no producer.
     */
    private function derivedNodeExpression(mixed $answer, SyntaxProducer $producer): IBooleanExpressionNode
    {
        if ($answer instanceof WarrantConditionBuilder) {
            /* A builder with no terms is the structural twin of a condition that
               added no where clause: it would mean "match everything", which is
               almost always a forgotten branch rather than an intent. */
            return $answer->buildConditions() ?? throw new InvalidArgumentException(sprintf(
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
                '%s must answer with an expression, a %s, rule text, a %s, a bool or null; got %s.',
                $producer->describe(),
                WarrantConditionBuilder::class,
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
     * A `for` header scopes the names to the schema it names, and the expression
     * is expanded and compiled against this one, so a header naming another is
     * rejected rather than read against the wrong vocabulary.
     */
    private function derivedSyntaxExpression(string|BoundSyntax|WarrantSyntax $text, SyntaxProducer $producer, string $schemaKey): IBooleanExpressionNode
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

        if ($syntax->isSchemaCondition() && $syntax->schemaKeys() !== [$schemaKey]) {
            throw new RuntimeException(sprintf(
                '%s answered with a condition for schema [%s]; it is a condition of schema [%s].',
                $producer->describe(),
                $syntax->schemaKeys()[0],
                $schemaKey,
            ));
        }

        $valid = self::validateAnswer($producer, fn () => ValidSyntax::of($syntax, new ValidationInput($this->catalog)));

        return self::withBindingsReplaced($valid->conditionExpression());
    }
}
