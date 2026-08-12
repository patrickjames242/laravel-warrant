<?php

namespace Warrant\RuleSyntaxTree;

use Closure;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;

/**
 * A fluent, Laravel-query-builder-style front-end for composing the boolean
 * condition tree of a {@see WarrantRule} — the `if`/`and`/`or` half of a rule.
 *
 * It produces AST nodes directly — the same tree the parser builds — so a built
 * condition flows through the identical validation and compilation. Nothing is
 * ever serialized to a string.
 *
 * Terms accumulate as a flat sequence joined by `and`/`or`; the tree is
 * materialized with the DSL's precedence (`not` > `and` > `or`), so the builder
 * and the string language always agree. A closure is a parenthesized group, and
 * receives a bare condition builder — a group has no `they can`/`they cannot`
 * clauses, only conditions.
 *
 * {@see WarrantRuleBuilder} extends this with the clause half (`theyCan` /
 * `theyCannot`) and `toRule()`.
 */
class WarrantConditionBuilder
{
    /** @var list<array{boolean: string, node: IBooleanExpressionNode}> */
    private array $terms = [];

    // -- condition terms ------------------------------------------------------

    /**
     * Add a condition, AND-joined to what precedes it (the default connective).
     *
     * @param string|Closure(WarrantConditionBuilder):void $condition A condition
     *   name, or a closure that receives a fresh condition builder to compose a
     *   parenthesized group.
     * @param array<int, mixed> $parameters
     */
    public function if(string|Closure $condition, array $parameters = []): static
    {
        return $this->addTerm('and', false, $condition, $parameters);
    }

    /** @param string|Closure(WarrantConditionBuilder):void $condition */
    public function andIf(string|Closure $condition, array $parameters = []): static
    {
        return $this->addTerm('and', false, $condition, $parameters);
    }

    /** @param string|Closure(WarrantConditionBuilder):void $condition */
    public function orIf(string|Closure $condition, array $parameters = []): static
    {
        return $this->addTerm('or', false, $condition, $parameters);
    }

    /** @param string|Closure(WarrantConditionBuilder):void $condition */
    public function ifNot(string|Closure $condition, array $parameters = []): static
    {
        return $this->addTerm('and', true, $condition, $parameters);
    }

    /** @param string|Closure(WarrantConditionBuilder):void $condition */
    public function andIfNot(string|Closure $condition, array $parameters = []): static
    {
        return $this->addTerm('and', true, $condition, $parameters);
    }

    /** @param string|Closure(WarrantConditionBuilder):void $condition */
    public function orIfNot(string|Closure $condition, array $parameters = []): static
    {
        return $this->addTerm('or', true, $condition, $parameters);
    }

    /**
     * Splice a DSL expression fragment in as one AND-joined group — author the
     * readable part as text, compose the rest structurally.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function ifRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('and', $expression, $bindings);
    }

    /** @param array<int|string, mixed> $bindings */
    public function orIfRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('or', $expression, $bindings);
    }

    /**
     * Conditionally apply builder calls, Laravel-style, for runtime branches.
     * The callback receives this same builder, so on a {@see WarrantRuleBuilder}
     * it may also add clauses.
     *
     * @param Closure(static, mixed):void $callback
     */
    public function when(mixed $condition, Closure $callback): static
    {
        if ($condition) {
            $callback($this, $condition);
        }

        return $this;
    }

    // -- materialization ------------------------------------------------------

    /**
     * Fold the flat term sequence into an expression tree using `and` > `or`
     * precedence. Returns null when there are no terms (an unconditional rule).
     */
    public function buildConditions(): ?IBooleanExpressionNode
    {
        if ($this->terms === []) {
            return null;
        }

        // Split into OR-segments; each segment is a run of AND-joined operands.
        $segments = [];
        $current = [];

        foreach ($this->terms as $index => $term) {
            if ($index > 0 && $term['boolean'] === 'or') {
                $segments[] = $current;
                $current = [];
            }

            $current[] = $term['node'];
        }

        $segments[] = $current;

        return $this->foldOr(array_map(fn (array $nodes) => $this->foldAnd($nodes), $segments));
    }

    /**
     * @param string|Closure(WarrantConditionBuilder):void $condition
     * @param array<int, mixed> $parameters
     */
    private function addTerm(string $boolean, bool $negate, string|Closure $condition, array $parameters): static
    {
        $node = $condition instanceof Closure
            ? $this->group($condition)
            : new ConditionNode($condition, $parameters);

        if ($negate) {
            $node = new NotNode($node);
        }

        $this->terms[] = ['boolean' => $boolean, 'node' => $node];

        return $this;
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function addRaw(string $boolean, string $expression, array $bindings): static
    {
        $this->terms[] = [
            'boolean' => $boolean,
            'node' => WarrantParser::parseConditionExpression($expression, $bindings),
        ];

        return $this;
    }

    /**
     * Resolve a group closure to a single operand. The closure receives a bare
     * condition builder (no clauses); an empty group is `false`.
     *
     * @param Closure(WarrantConditionBuilder):void $callback
     */
    private function group(Closure $callback): IBooleanExpressionNode
    {
        $sub = new self;
        $callback($sub);

        return $sub->buildConditions() ?? new BooleanNode(false);
    }

    /**
     * @param list<IBooleanExpressionNode> $nodes
     */
    private function foldAnd(array $nodes): IBooleanExpressionNode
    {
        $accumulator = array_shift($nodes);

        foreach ($nodes as $node) {
            $accumulator = new AndNode($accumulator, $node);
        }

        return $accumulator;
    }

    /**
     * @param list<IBooleanExpressionNode> $nodes
     */
    private function foldOr(array $nodes): IBooleanExpressionNode
    {
        $accumulator = array_shift($nodes);

        foreach ($nodes as $node) {
            $accumulator = new OrNode($accumulator, $node);
        }

        return $accumulator;
    }
}
