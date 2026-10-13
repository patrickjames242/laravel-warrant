<?php

namespace Warrant\DSL\Parsing;

use Closure;
use LogicException;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Positions\SourceMap;

/**
 * Puts the value of every binding in a tree read from $source in its place:
 * each {@see BindingNode} is replaced by the value it stands for, under the
 * rules {@see BindingState} enforces. A binding that stands for the body of an
 * `@sql` reference must have a string for a value, and one that stands for a
 * denial message a string or a closure.
 *
 * The bindings are taken in the order they were written, which is the order
 * positional values are given in and errors are reported in. That order comes
 * from where $positions says each binding was written, so it does not depend
 * on the order a node holds its parts in. The same positions are where an
 * error about a binding points.
 */
final class BindingResolver
{
    private const AS_ARGUMENT = 'argument';
    private const AS_SQL = 'sql';
    private const AS_MESSAGE = 'message';

    public function __construct(
        private readonly string $source,
        private readonly SourceMap $positions,
    ) {
    }

    /**
     * $syntax with the value of every binding in its children. Nodes are
     * immutable, so a tree with bindings is built anew; what was written stays
     * as it was, in the syntax's {@see WarrantSyntax::$written}, which is the
     * tree $positions describes. A tree with no bindings keeps its nodes.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function resolve(WarrantSyntax $syntax, array $bindings): WarrantSyntax
    {
        $state = new BindingState($this->source, $bindings);
        $values = [];

        foreach ($this->bindingsOf($syntax) as [$binding, $standsFor]) {
            $offset = $this->offsetOf($binding);
            $value = $binding->isPositional()
                ? $state->resolvePositional($offset)
                : $state->resolveNamed($binding->name, $offset);

            $this->assertValueFits($value, $standsFor, $offset);
            $values[spl_object_id($binding)] = $value;
        }

        $state->finalize();

        if ($values === []) {
            return new WarrantSyntax($syntax->children, $syntax->source, $syntax->positions, $syntax->written);
        }

        return $this->withBindings(
            $syntax,
            static fn (BindingNode $binding): mixed => $values[spl_object_id($binding)],
        );
    }

    /**
     * Every binding in $syntax, in the order written.
     *
     * @return list<BindingNode>
     */
    public function bindingsInOrder(WarrantSyntax $syntax): array
    {
        return array_map(static fn (array $found): BindingNode => $found[0], $this->bindingsOf($syntax));
    }

    /**
     * Every binding in $syntax, with what it stands for, in the order written.
     *
     * @return list<array{0: BindingNode, 1: string}>
     */
    private function bindingsOf(WarrantSyntax $syntax): array
    {
        $found = [];

        $this->withBindings($syntax, static function (BindingNode $binding, string $standsFor) use (&$found): BindingNode {
            $found[] = [$binding, $standsFor];

            return $binding;
        });

        usort($found, fn (array $a, array $b): int => $this->offsetOf($a[0]) <=> $this->offsetOf($b[0]));

        return $found;
    }

    private function offsetOf(BindingNode $binding): int
    {
        return $this->positions->spanOf($binding)?->offset
            ?? throw new LogicException('A binding in the tree has no recorded position.');
    }

    private function assertValueFits(mixed $value, string $standsFor, int $offset): void
    {
        if ($standsFor === self::AS_SQL && ! is_string($value)) {
            throw WarrantSyntaxException::atSourceOffset(
                DiagnosticCode::InvalidBindingValue,
                sprintf('An @sql binding must resolve to a string, got %s.', get_debug_type($value)),
                $this->source,
                $offset,
            );
        }

        if ($standsFor === self::AS_MESSAGE && ! is_string($value) && ! $value instanceof Closure) {
            throw WarrantSyntaxException::atSourceOffset(
                DiagnosticCode::InvalidBindingValue,
                sprintf('A denial message must be a string or a closure, got %s.', get_debug_type($value)),
                $this->source,
                $offset,
            );
        }
    }

    /**
     * $node built anew with every binding in it replaced by what $replace
     * answers for it, given the binding and what it stands for.
     *
     * @template N
     * @param N $node
     * @param Closure(BindingNode, string): mixed $replace
     * @return N
     */
    private function withBindings(
        mixed $node,
        Closure $replace,
        string $standsFor = self::AS_ARGUMENT,
    ): mixed {
        $walk = fn (mixed $one, string $standsFor = self::AS_ARGUMENT): mixed
            => $this->withBindings($one, $replace, $standsFor);
        $each = static fn (array $nodes): array => array_map($walk, $nodes);

        if ($node instanceof BindingNode) {
            return $replace($node, $standsFor);
        }

        return match (true) {
            $node instanceof WarrantSyntax => new WarrantSyntax(
                $each($node->children),
                $node->source,
                $node->positions,
                $node->written,
            ),
            $node instanceof RuleSetNode => new RuleSetNode($node->schemaKey, $each($node->entries)),
            $node instanceof SchemaConditionNode => new SchemaConditionNode($node->schemaKey, $walk($node->expression)),
            $node instanceof AbilityBlockNode => new AbilityBlockNode($node->abilities, $each($node->entries)),
            $node instanceof WarrantRuleNode => new WarrantRuleNode(
                $walk($node->conditions),
                $each($node->canClauses),
                $each($node->cannotClauses),
            ),
            $node instanceof CanClauseNode => new CanClauseNode(
                $node->abilities,
                $this->withBindings($node->message, $replace, self::AS_MESSAGE),
            ),
            $node instanceof CannotClauseNode => new CannotClauseNode(
                $node->abilities,
                $walk($node->message, self::AS_MESSAGE),
            ),
            $node instanceof IncludeInvocationNode => new IncludeInvocationNode(
                $node->templateKey,
                $each($node->arguments),
                $node->abilities,
            ),
            $node instanceof AndNode => new AndNode($walk($node->leftSide), $walk($node->rightSide)),
            $node instanceof OrNode => new OrNode($walk($node->leftSide), $walk($node->rightSide)),
            $node instanceof NotNode => new NotNode($walk($node->operand)),
            $node instanceof ConditionNode => new ConditionNode($node->conditionKey, $each($node->parameters)),
            $node instanceof CrossSchemaCanNode => new CrossSchemaCanNode(
                $node->schemaKey,
                $node->ability,
                $node->isRowBound,
                $each($node->boundKey),
                $each($node->contextMap),
                $node->alias,
            ),
            $node instanceof CrossSchemaConditionNode => new CrossSchemaConditionNode(
                $node->schemaKey,
                $walk($node->predicate),
                $node->isRowBound,
                $each($node->boundKey),
                $each($node->contextMap),
                $node->alias,
            ),
            $node instanceof SqlRef => new SqlRef($walk($node->sql, self::AS_SQL)),
            default => $node,
        };
    }
}
