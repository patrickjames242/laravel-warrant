<?php

namespace Warrant\Builders;

use Closure;
use LogicException;
use Warrant\Builders\Concerns\PlacesNodes;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Parsing\WarrantParser;

/**
 * A fluent, Laravel-query-builder-style front-end for composing the boolean
 * condition tree of a {@see \Warrant\DSL\Parsing\ASTNodes\RuleNode} — the
 * `if`/`and`/`or` half of a rule. {@see Build::condition()} makes one.
 *
 * It produces AST nodes directly — the same tree the parser builds — so a built
 * condition flows through the identical validation and compilation. Nothing is
 * ever serialized to a string.
 *
 * It holds what it was given and makes no node until it is resolved, by
 * {@see buildConditions()} with the source of the syntax resolving it, so every
 * node it makes, through nested groups and predicates too, says what handed it
 * over. A builder reused in two places is resolved in each, as two trees. A
 * {@see Ref} is a node already, and keeps its own source; so does a raw
 * condition's text, which keeps its `rawCondition()` producer.
 *
 * Terms accumulate as a flat sequence joined by `and`/`or`; the tree is
 * materialized with the DSL's precedence (`not` > `and` > `or`), so the builder
 * and the string language always agree. A closure is a parenthesized group, and
 * receives a bare condition builder — a group has no `they can`/`they cannot`
 * can/cannot clauses, only conditions.
 *
 * {@see WarrantRuleBuilder} extends this with the can/cannot clause half (`theyCan` /
 * `theyCannot`) and `toRule()`.
 */
class WarrantConditionBuilder
{
    use PlacesNodes;

    /** @var list<array{boolean: string, node: Closure(SyntaxSource): BooleanExpressionNode}> */
    private array $terms = [];

    // -- condition terms ------------------------------------------------------

    /**
     * Add a condition, AND-joined to what precedes it (the default connective).
     *
     * @param string|Closure(WarrantConditionBuilder):mixed|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition
     *   A condition name; a closure that receives a fresh condition builder to
     *   compose a parenthesized group, its return value ignored; a bool, folded
     *   into the tree as a constant; a condition builder, spliced in as a group,
     *   an empty one as `false`; an expression node; or a {@see Build::can()} or
     *   {@see Build::check()} reference.
     * @param array<int, mixed> $parameters The condition's arguments; only a
     *   condition name takes them.
     */
    public function if(string|Closure|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters = []): static
    {
        return $this->addTerm('and', false, $condition, $parameters);
    }

    /** @param array<int, mixed> $parameters */
    public function andIf(string|Closure|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters = []): static
    {
        return $this->addTerm('and', false, $condition, $parameters);
    }

    /** @param array<int, mixed> $parameters */
    public function orIf(string|Closure|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters = []): static
    {
        return $this->addTerm('or', false, $condition, $parameters);
    }

    /** @param array<int, mixed> $parameters */
    public function ifNot(string|Closure|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters = []): static
    {
        return $this->addTerm('and', true, $condition, $parameters);
    }

    /** @param array<int, mixed> $parameters */
    public function andIfNot(string|Closure|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters = []): static
    {
        return $this->addTerm('and', true, $condition, $parameters);
    }

    /** @param array<int, mixed> $parameters */
    public function orIfNot(string|Closure|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters = []): static
    {
        return $this->addTerm('or', true, $condition, $parameters);
    }

    // -- cross-schema terms ---------------------------------------------------

    /**
     * Add a cross-schema ability reference, `can(...)`, AND-joined to what
     * precedes it. Negate one with `ifNot(Build::can(...))`.
     *
     * @param string|CrossSchemaCanBuilder|Closure(Closure(string): CrossSchemaCanBuilder): mixed $ability
     *   An ability on this same schema, so `ifCan('edit')` is `can(edit)`; a
     *   {@see Build::can()} reference; or a closure handed a factory that starts
     *   one from its ability, `fn ($can) => $can('approve')->for(...)`, which it
     *   calls exactly once. Its return value is ignored.
     */
    public function ifCan(string|CrossSchemaCanBuilder|Closure $ability): static
    {
        return $this->addCan('and', $ability);
    }

    /** @param string|CrossSchemaCanBuilder|Closure(Closure(string): CrossSchemaCanBuilder): mixed $ability */
    public function andIfCan(string|CrossSchemaCanBuilder|Closure $ability): static
    {
        return $this->addCan('and', $ability);
    }

    /** @param string|CrossSchemaCanBuilder|Closure(Closure(string): CrossSchemaCanBuilder): mixed $ability */
    public function orIfCan(string|CrossSchemaCanBuilder|Closure $ability): static
    {
        return $this->addCan('or', $ability);
    }

    /**
     * Add a cross-schema condition check, `check(...)`, AND-joined to what
     * precedes it: a question delegated to another schema, answered against that
     * schema's own vocabulary. Negate one with `ifNot(Build::check(...))`.
     *
     * There is no string form: a bare condition name has no schema to be checked
     * against.
     *
     * @param CrossSchemaCheckBuilder|Closure(CrossSchemaCheckBuilder): mixed $check
     *   A {@see Build::check()} reference, or a closure handed a fresh one to
     *   configure in place, `fn ($check) => $check->if('is_open')->for(...)`. Its
     *   return value is ignored.
     */
    public function ifCheck(CrossSchemaCheckBuilder|Closure $check): static
    {
        return $this->addCheck('and', $check);
    }

    /** @param CrossSchemaCheckBuilder|Closure(CrossSchemaCheckBuilder): mixed $check */
    public function andIfCheck(CrossSchemaCheckBuilder|Closure $check): static
    {
        return $this->addCheck('and', $check);
    }

    /** @param CrossSchemaCheckBuilder|Closure(CrossSchemaCheckBuilder): mixed $check */
    public function orIfCheck(CrossSchemaCheckBuilder|Closure $check): static
    {
        return $this->addCheck('or', $check);
    }

    // -- raw terms ------------------------------------------------------------

    /**
     * Splice a DSL expression fragment in as one AND-joined group — author the
     * readable part as text, compose the rest structurally.
     *
     * @param array<string, mixed> $bindings
     */
    public function ifRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('and', false, $expression, $bindings);
    }

    /** @param array<string, mixed> $bindings */
    public function andIfRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('and', false, $expression, $bindings);
    }

    /** @param array<string, mixed> $bindings */
    public function orIfRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('or', false, $expression, $bindings);
    }

    /** @param array<string, mixed> $bindings */
    public function ifNotRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('and', true, $expression, $bindings);
    }

    /** @param array<string, mixed> $bindings */
    public function andIfNotRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('and', true, $expression, $bindings);
    }

    /** @param array<string, mixed> $bindings */
    public function orIfNotRaw(string $expression, array $bindings = []): static
    {
        return $this->addRaw('or', true, $expression, $bindings);
    }

    // -- runtime branches -----------------------------------------------------

    /**
     * Apply $callback when $value is truthy, else $default when given,
     * Laravel-style. Each receives this same builder and $value, so on a
     * {@see WarrantRuleBuilder} it may also add can/cannot clauses.
     *
     * @param Closure(static, mixed): mixed $callback
     * @param (Closure(static, mixed): mixed)|null $default
     */
    public function when(mixed $value, Closure $callback, ?Closure $default = null): static
    {
        if ($value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }

    /**
     * Apply $callback when $value is falsy, else $default when given.
     *
     * @param Closure(static, mixed): mixed $callback
     * @param (Closure(static, mixed): mixed)|null $default
     */
    public function unless(mixed $value, Closure $callback, ?Closure $default = null): static
    {
        if (! $value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }

    // -- materialization ------------------------------------------------------

    public function hasTerms(): bool
    {
        return $this->terms !== [];
    }

    /**
     * Fold the flat term sequence into an expression tree using `and` > `or`
     * precedence, every node placed in $source. Returns null when there are no
     * terms (an unconditional rule).
     */
    public function buildConditions(SyntaxSource $source): ?BooleanExpressionNode
    {
        if ($this->terms === []) {
            return null;
        }

        /* Split into OR-segments; each segment is a run of AND-joined operands. */
        $segments = [];
        $current = [];

        foreach ($this->terms as $index => $term) {
            if ($index > 0 && $term['boolean'] === 'or') {
                $segments[] = $current;
                $current = [];
            }

            $current[] = ($term['node'])($source);
        }

        $segments[] = $current;

        return $this->foldOr(array_map(fn (array $nodes) => $this->foldAnd($nodes, $source), $segments), $source);
    }

    /**
     * @param array<int, mixed> $parameters
     */
    private function addTerm(string $boolean, bool $negate, string|Closure|bool|WarrantConditionBuilder|BooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters): static
    {
        if ($parameters !== [] && ! is_string($condition)) {
            throw new LogicException(sprintf(
                'Parameters only go with a condition name; %s takes none. Pass them inside the group instead.',
                get_debug_type($condition),
            ));
        }

        if ($condition instanceof WarrantRuleBuilder) {
            throw new LogicException(
                'A rule is not a condition; pass a condition builder from Build::condition(), or the rule\'s conditions themselves.'
            );
        }

        if ($condition instanceof Closure) {
            $group = new WarrantConditionBuilder;
            $condition($group);
            $condition = $group;
        }

        $node = match (true) {
            is_string($condition) => fn (SyntaxSource $source): BooleanExpressionNode => new ConditionNode(
                self::nameNode($condition, $source),
                array_map(fn (mixed $parameter) => self::argumentNode($parameter, $source), array_values($parameters)),
                self::location($source),
            ),
            is_bool($condition) => fn (SyntaxSource $source): BooleanExpressionNode => new BooleanNode($condition, self::location($source)),
            $condition instanceof CrossSchemaCheckBuilder => $condition->assertComplete()->toNode(...),
            $condition instanceof CrossSchemaCanBuilder => $condition->toNode(...),
            $condition instanceof WarrantConditionBuilder => fn (SyntaxSource $source): BooleanExpressionNode => $condition->buildConditions($source)
                ?? new BooleanNode(false, self::location($source)),
            default => fn (): BooleanExpressionNode => $condition,
        };

        return $this->pushTerm($boolean, $negate, $node);
    }

    /**
     * @param string|CrossSchemaCanBuilder|Closure(Closure(string): CrossSchemaCanBuilder): mixed $ability
     */
    private function addCan(string $boolean, string|CrossSchemaCanBuilder|Closure $ability): static
    {
        if (is_string($ability)) {
            $ability = new CrossSchemaCanBuilder($ability);
        } elseif ($ability instanceof Closure) {
            $made = [];
            $ability(function (string $name) use (&$made): CrossSchemaCanBuilder {
                return $made[] = new CrossSchemaCanBuilder($name);
            });

            if (count($made) !== 1) {
                throw new LogicException(sprintf(
                    'An ifCan() closure starts exactly one can(...) reference by calling the factory it is handed once; it called it %d times.',
                    count($made),
                ));
            }

            $ability = $made[0];
        }

        return $this->pushTerm($boolean, false, $ability->toNode(...));
    }

    /**
     * @param CrossSchemaCheckBuilder|Closure(CrossSchemaCheckBuilder): mixed $check
     */
    private function addCheck(string $boolean, CrossSchemaCheckBuilder|Closure $check): static
    {
        if ($check instanceof Closure) {
            $configure = $check;
            $check = new CrossSchemaCheckBuilder;
            $configure($check);
        }

        return $this->pushTerm($boolean, false, $check->assertComplete()->toNode(...));
    }

    /**
     * The expression is parsed here, as a raw condition in a source of its own,
     * so its nodes keep that producer wherever the built rule is handed over.
     *
     * @param array<string, mixed> $bindings
     */
    private function addRaw(string $boolean, bool $negate, string $expression, array $bindings): static
    {
        $node = WarrantParser::parse(new BoundSyntax($expression, $bindings), SyntaxProducer::rawCondition())
            ->validateWithoutSchemas()
            ->conditionExpression();

        return $this->pushTerm($boolean, $negate, fn (): BooleanExpressionNode => $node);
    }

    /**
     * Append an operand to the flat term sequence, made in the source the
     * builder is resolved in.
     *
     * @param Closure(SyntaxSource): BooleanExpressionNode $node
     */
    private function pushTerm(string $boolean, bool $negate, Closure $node): static
    {
        if ($negate) {
            $node = fn (SyntaxSource $source): BooleanExpressionNode => new NotNode($node($source), self::location($source));
        }

        $this->terms[] = ['boolean' => $boolean, 'node' => $node];

        return $this;
    }

    /**
     * @param list<BooleanExpressionNode> $nodes
     */
    private function foldAnd(array $nodes, SyntaxSource $source): BooleanExpressionNode
    {
        $accumulator = array_shift($nodes);

        foreach ($nodes as $node) {
            $accumulator = new AndNode($accumulator, $node, self::location($source));
        }

        return $accumulator;
    }

    /**
     * @param list<BooleanExpressionNode> $nodes
     */
    private function foldOr(array $nodes, SyntaxSource $source): BooleanExpressionNode
    {
        $accumulator = array_shift($nodes);

        foreach ($nodes as $node) {
            $accumulator = new OrNode($accumulator, $node, self::location($source));
        }

        return $accumulator;
    }
}
