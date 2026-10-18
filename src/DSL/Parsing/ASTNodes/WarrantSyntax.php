<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use LogicException;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Parsing\Validation\GenericMode;
use Warrant\DSL\Parsing\Validation\ValidationInput;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\Writing\RuleSyntaxWriter;
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
 * nodes out — {@see rule()}, {@see scopedTo()} and the rest — take them from
 * {@see asValid()}, so a node never leaves a syntax unvalidated.
 *
 * Two parses of the same rules written differently hold children that say the
 * same, each placed where it was written.
 */
readonly class WarrantSyntax implements INode
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

        $this->source = $source ?? new SyntaxSource(null, SyntaxProducer::code());
    }

    /**
     * Parse rule text of any form, keeping the values a {@see BoundSyntax} holds
     * for its named (:name) or positional (?) placeholders in the source each
     * {@see BindingNode} finds its value in.
     * A string is rule text with no bindings. $producer says what produced the
     * syntax; with none, application code parsed it directly.
     */
    public static function parse(string|BoundSyntax $syntax, ?SyntaxProducer $producer = null): self
    {
        return WarrantParser::parse($syntax, $producer);
    }

    /**
     * This syntax, validated as written: every condition and ability name in it
     * checked against the schema the application registers for it, and every
     * rule of the language it is held to. Throws the first mistake, which says
     * where in the text it is.
     *
     * Each `for <schema>` body is checked against the schema its header names.
     * Rules with no header are checked as rules for $schemaKey, each naming the
     * abilities it applies to; with no $schemaKey they belong to no schema yet,
     * and are held only to the rules of the language that need none.
     *
     * What is checked is the text as written. The rules a template answers with
     * and the expression a derived condition answers with are texts of their
     * own, checked when a guard expands the rules that name them.
     */
    public function validate(?string $schemaKey = null): ValidSyntax
    {
        return ValidSyntax::of($this, new ValidationInput(
            new RegisteredSchemas,
            $schemaKey,
            $schemaKey === null ? GenericMode::Either : GenericMode::RequireNotGeneric,
        ));
    }

    /**
     * This syntax, valid. A syntax not yet validated is validated as written
     * and against no schema, so it is held to the rules of the language that
     * need none, its bindings' values among them. Throws the first mistake.
     */
    protected function asValid(): ValidSyntax
    {
        return ValidSyntax::of($this, new ValidationInput(null));
    }

    /**
     * Parse the rule text in the file at $path, read as
     * {@see BoundSyntax::fromFile()} reads it.
     *
     * @param array<int|string, mixed> $bindings
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
     * blocks or includes, with no `for` header.
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
     * The condition this source holds: a bare expression, or the one expression
     * under a single `for <schema>` header, which scopes the names without
     * changing the tree.
     */
    public function conditionExpression(): IBooleanExpressionNode
    {
        if (! $this->isExpression() && ! $this->isSchemaCondition()) {
            throw $this->shapeError('a condition expression');
        }

        $child = $this->asValid()->children[0];

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

        return $this->asValid()->children[0];
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
        return $this->asValid()->children;
    }

    /**
     * The one `for <schema>` rule set this source holds.
     */
    public function ruleSet(): RuleSetNode
    {
        if (! $this->isSingleRuleSet()) {
            throw $this->shapeError('a single `for <schema>` rule set');
        }

        return $this->asValid()->children[0];
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
        return $this->asValid()->children;
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
        return $this->asValid()->children;
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
     * Every rule set for $schemaKey folded into one, in source order, or null
     * when the source never names that schema.
     */
    public function forSchema(string $schemaKey): ?RuleSetNode
    {
        $matching = array_values(array_filter(
            $this->ruleSets(),
            static fn (RuleSetNode $ruleSet): bool => $ruleSet->schemaKey === $schemaKey,
        ));

        return $matching === [] ? null : RuleSetNode::merge(...$matching);
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
                $schemaKey,
                $this->ruleEntries(),
                SyntaxLocation::builtBy($this->source->producer),
            );
        }

        $ruleSet = $this->ruleSet();

        if ($ruleSet->schemaKey !== $schemaKey) {
            throw new InvalidArgumentException(sprintf(
                'The rule text targets schema [%s] in its `for` header but was scoped to [%s].',
                $ruleSet->schemaKey,
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
        return RuleSyntaxWriter::toSyntax($this->asValid());
    }

    /**
     * Render the tree to `?`-parameterized rule text plus one flat, left-to-right
     * positional bindings list. Lossless for any value.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::toBoundSyntax($this->asValid());
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

    private function shapeError(string $expected): LogicException
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
            $this->isRuleEntries() => sprintf('%d unscoped rule entries', count($this->children)),
            $first instanceof RuleSetNode && count($this->children) === 1 => sprintf('a rule set for [%s]', $first->schemaKey),
            $first instanceof SchemaConditionNode && count($this->children) === 1 => sprintf('a condition for [%s]', $first->schemaKey),
            default => sprintf('%d `for <schema>` bodies', count($this->children)),
        };
    }
}
