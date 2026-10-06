<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use LogicException;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\Writing\BoundSyntax;
use Warrant\DSL\Parsing\Writing\RuleSyntaxWriter;

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
 */
final readonly class WarrantSyntax implements INode
{
    /**
     * @param list<INode> $children
     */
    public function __construct(
        public array $children = [],
    ) {
        self::assertOneShape($children);
    }

    /**
     * Parse rule text of any form, resolving named (:name) or positional (?)
     * placeholders against $bindings.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parse(string $source, array $bindings = []): self
    {
        return WarrantParser::parse($source, $bindings);
    }

    /**
     * Parse the rule text in the file at $path.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parseFile(string $path, array $bindings = []): self
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new InvalidArgumentException(sprintf('Unable to read Warrant rule file [%s].', $path));
        }

        return self::parse($source, $bindings);
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
        $child = $this->children[0] ?? null;

        return match (true) {
            $this->isExpression() && $child instanceof IBooleanExpressionNode => $child,
            $this->isSchemaCondition() && $child instanceof SchemaConditionNode => $child->expression,
            default => throw $this->shapeError('a condition expression'),
        };
    }

    /**
     * The one unscoped rule this source holds.
     */
    public function rule(): WarrantRuleNode
    {
        $child = $this->children[0] ?? null;

        if (! $this->isSingleRule() || ! $child instanceof WarrantRuleNode) {
            throw $this->shapeError('a single rule');
        }

        return $child;
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
        $child = $this->children[0] ?? null;

        if (! $this->isSingleRuleSet() || ! $child instanceof RuleSetNode) {
            throw $this->shapeError('a single `for <schema>` rule set');
        }

        return $child;
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
     * The distinct schema keys the `for` headers name, in source order.
     *
     * @return list<string>
     */
    public function schemaKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (ISchemaScopedNode $node): string => $node->schemaKey(),
            $this->scoped(),
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
     * entries at all, are placed in a rule set for that schema; a single
     * `for <schema>` rule set must already name it. A header and a schema that
     * disagree is an error, never a silent choice.
     */
    public function scopedTo(string $schemaKey): RuleSetNode
    {
        if ($this->isEmpty() || $this->isRuleEntries()) {
            return new RuleSetNode($schemaKey, $this->ruleEntries());
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

    private function describe(): string
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
