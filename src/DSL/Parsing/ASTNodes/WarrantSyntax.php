<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use LogicException;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\Registry\RegisteredSchemas;

/**
 * The root of every parse. Its children are exactly what the source held, in one
 * of four shapes:
 *
 *   - nothing                         an empty source
 *   - one IBooleanExpressionNode      a bare condition
 *   - IRuleEntryNode, ...             unscoped rules, ability blocks, includes
 *   - ISchemaScopedNode, ...          one bare `for` body, or braced blocks
 *
 * The shapes never mix. The caller asks which one it got, so one {@see parse()}
 * reads every form of rule text and nothing about the text is known before it
 * is read.
 *
 * A parsed syntax is one block of rule syntax someone wrote: its
 * {@see SyntaxSource} holds the text, the values given for its bindings, and
 * what produced it, and every node of it carries the {@see SyntaxLocation} it
 * was written at. Every `:name` and `?` stays a {@see BindingNode} in the
 * children, which finds its value in the source, so the tree is always the text
 * as it was written.
 *
 * A syntax is validated into a {@see ValidSyntax}, a type that only validation
 * makes, and only a valid syntax can be expanded into an {@see ExpandedSyntax},
 * which is no block of text but the rules of many. The accessors that hand
 * nodes out — {@see ValidSyntax::rule()}, {@see ValidSyntax::scopedTo()} and
 * the rest — are a valid syntax's, so a node never leaves a syntax
 * unvalidated. This class answers only which shape its children are.
 *
 * Two parses of the same rules written differently hold children that say the
 * same, each placed where it was written.
 */
readonly class WarrantSyntax
{
    /**
     * Where the children came from: the text they were read from with the values
     * for their bindings, or for a syntax built in code only what produced it.
     */
    public SyntaxSource $source;

    /**
     * @param list<INode> $children
     * @param SyntaxSource|null $source Where the children came from; with none,
     *   application code built them.
     */
    public function __construct(
        public array $children = [],
        ?SyntaxSource $source = null,
    ) {
        self::assertOneShape($children);

        $this->source = $source ?? new SyntaxSource(null);
    }

    /**
     * Parse rule text of any form, keeping the values a {@see BoundSyntax} holds
     * for its `:name` bindings in the source each
     * {@see BindingNode} finds its value in.
     * A string is rule text with no bindings. Nothing has handed the syntax over
     * yet, so it has no producer: the backend sets one when it receives it.
     */
    public static function parse(string|BoundSyntax $syntax): self
    {
        return WarrantParser::parse($syntax);
    }

    /**
     * This syntax, validated as written: every condition and ability name in it
     * checked against the schema the application registers for it, and every
     * rule of the language it is held to. Throws the first mistake, which says
     * where in the text it is.
     *
     * Each `for <schema>` body is checked against the schema its header names.
     * Rules with no header are checked against the schema the syntax is for,
     * as what handed it over says; before anything has, they belong to no
     * schema, and are held to every rule of the language that needs none.
     *
     * What is checked is the text as written. The rules a template answers with
     * and the expression a derived condition answers with are texts of their
     * own, checked when a guard expands the rules that name them.
     */
    public function validate(): ValidSyntax
    {
        return ValidSyntax::of($this, new ValidationInput(new RegisteredSchemas));
    }

    /**
     * This syntax, validated as written against no schema, where none can be
     * looked up, as outside a running application. Names are not checked
     * against any vocabulary; every rule of the language that needs none is,
     * its bindings' values among them. Throws the first mistake.
     */
    public function validateWithoutSchemas(): ValidSyntax
    {
        return ValidSyntax::of($this, new ValidationInput(null));
    }

    /**
     * Parse the rule text in the file at $path, read as
     * {@see BoundSyntax::fromFile()} reads it.
     *
     * @param array<string, mixed> $bindings
     */
    public static function parseFile(string $path, array $bindings = []): self
    {
        return self::parse(BoundSyntax::fromFile($path, $bindings));
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }

    /**
     * Whether the source is a bare condition expression.
     */
    public function isExpression(): bool
    {
        return count($this->children) === 1 && $this->children[0] instanceof IBooleanExpressionNode;
    }

    /**
     * Whether the source is exactly one unscoped rule.
     */
    public function isSingleRule(): bool
    {
        return count($this->children) === 1 && $this->children[0] instanceof WarrantRuleNode;
    }

    /**
     * Whether the source is unscoped rule entries: one or more rules, ability
     * blocks, when blocks or includes, with no `for` header.
     */
    public function isRuleEntries(): bool
    {
        return $this->children !== [] && $this->children[0] instanceof IRuleEntryNode;
    }

    /**
     * Whether the source is one or more `for <schema>` bodies.
     */
    public function isSchemaScoped(): bool
    {
        return $this->children !== [] && $this->children[0] instanceof ISchemaScopedNode;
    }

    /**
     * Whether the source is exactly one `for <schema>` rule set.
     */
    public function isSingleRuleSet(): bool
    {
        return count($this->children) === 1 && $this->children[0] instanceof RuleSetNode;
    }

    /**
     * Whether the source is one or more `for <schema>` bodies, every one a rule
     * set.
     */
    public function isRuleSets(): bool
    {
        if (! $this->isSchemaScoped()) {
            return false;
        }

        foreach ($this->children as $child) {
            if (! $child instanceof RuleSetNode) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the source is exactly one condition under a `for <schema>` header.
     */
    public function isSchemaCondition(): bool
    {
        return count($this->children) === 1 && $this->children[0] instanceof SchemaConditionNode;
    }

    /**
     * The distinct schema keys the `for` headers name, in source order.
     *
     * @return list<string>
     */
    public function schemaKeys(): array
    {
        if (! $this->isEmpty() && ! $this->isSchemaScoped()) {
            throw $this->shapeError('`for <schema>` bodies');
        }

        return array_values(array_unique(array_map(
            static fn (ISchemaScopedNode $node): string => $node->schemaKey(),
            $this->children,
        )));
    }

    /**
     * Hold the children to one of the four shapes in the class docblock: none,
     * one expression, rule entries, or `for <schema>` bodies.
     *
     * The first child decides the shape and every other child must match it.
     * Rule entries and `for` bodies may come in any number, because each is
     * complete on its own. An expression may not, because two expressions side
     * by side have no operator joining them, so they mean nothing as a whole.
     *
     * The parser never builds a mixed tree, so this guards a tree built in code.
     * Without it, a tree holding a rule beside a rule set would answer false to
     * every shape check, and each accessor would reject it with a message that
     * names no particular shape.
     *
     * @param list<INode> $children
     */
    private static function assertOneShape(array $children): void
    {
        if ($children === []) {
            return;
        }

        $kind = self::kindOf($children[0]);

        if ($kind === IBooleanExpressionNode::class && count($children) > 1) {
            throw new InvalidArgumentException('A Warrant syntax tree holds at most one condition expression.');
        }

        foreach ($children as $child) {
            if (self::kindOf($child) !== $kind) {
                throw new InvalidArgumentException(sprintf(
                    'A Warrant syntax tree holds one kind of child; found %s beside %s.',
                    get_debug_type($child),
                    get_debug_type($children[0]),
                ));
            }
        }
    }

    /**
     * @return class-string
     */
    private static function kindOf(mixed $node): string
    {
        return match (true) {
            $node instanceof IBooleanExpressionNode => IBooleanExpressionNode::class,
            $node instanceof IRuleEntryNode => IRuleEntryNode::class,
            $node instanceof ISchemaScopedNode => ISchemaScopedNode::class,
            default => throw new InvalidArgumentException(sprintf(
                'A Warrant syntax tree cannot hold %s.',
                get_debug_type($node),
            )),
        };
    }

    protected function shapeError(string $expected): LogicException
    {
        return new LogicException(sprintf('Expected %s, but the source holds %s.', $expected, $this->describe()));
    }

    /**
     * Which of the four shapes this syntax holds, in words.
     */
    public function describe(): string
    {
        $first = $this->children[0] ?? null;

        return match (true) {
            $this->isEmpty() => 'nothing',
            $this->isExpression() => 'a condition expression',
            $this->isSingleRule() => 'a single rule',
            $first instanceof AbilityBlockNode && count($this->children) === 1 => 'an ability block',
            $first instanceof IncludeInvocationNode && count($this->children) === 1 => 'an @include',
            $first instanceof WhenBlockNode && count($this->children) === 1 => 'a when block',
            $this->isRuleEntries() => sprintf('%d unscoped rule entries', count($this->children)),
            $first instanceof RuleSetNode && count($this->children) === 1 => sprintf('a rule set for [%s]', $first->schemaKey()),
            $first instanceof SchemaConditionNode && count($this->children) === 1 => sprintf('a condition for [%s]', $first->schemaKey()),
            default => sprintf('%d `for <schema>` bodies', count($this->children)),
        };
    }
}
