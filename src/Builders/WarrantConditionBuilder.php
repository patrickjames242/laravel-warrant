<?php

namespace Warrant\Builders;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\Facades\Warrant;
use Warrant\Schema\WarrantSchema;

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
 * The DSL's two cross-schema leaves are reachable structurally too, through
 * `ifCan()` / `ifCheck()` and their `and`/`or` variants. Neither has a negated
 * form: negate one with the group form, `ifNot(fn ($c) => $c->ifCan(...))`.
 *
 * {@see WarrantRuleBuilder} extends this with the clause half (`theyCan` /
 * `theyCannot`) and `toRule()`.
 */
class WarrantConditionBuilder
{
    /** @var list<array{boolean: string, node: IBooleanExpressionNode}> */
    private array $terms = [];

    /**
     * Start a fluent, query-builder-style condition, the way
     * {@see \Warrant\Rules\WarrantRule::build()} starts a whole rule.
     *
     * This is how a schema's own condition composes the expression it derives
     * itself from, rather than emitting SQL:
     *
     * ```php
     * #[RowCondition]
     * public function isEditable(RowConditionContext $c): WarrantConditionBuilder
     * {
     *     return WarrantConditionBuilder::build()->if('is_owner')->orIf('is_admin');
     * }
     * ```
     *
     * Late static binding keeps the subclass: {@see WarrantRuleBuilder::build()}
     * returns a rule builder, clauses and all.
     */
    public static function build(): static
    {
        return new static;
    }

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

    // -- cross-schema terms ---------------------------------------------------

    /**
     * Add a cross-schema ability check — `can(<ability> for <handle> [as <alias>] [with <map>])`
     * — AND-joined to what precedes it: does the user hold $ability on *another*
     * schema? Unlike {@see ifCheck} this consults that schema's whole rule set.
     *
     * Omit $row for an unbound question — a schema-wide or capability-schema
     * handle. Pass a row key, the target schema's model, or a {@see Ref} to target
     * one row. An explicit `row: null` stays row-bound and is rejected at
     * validation; only omitting the argument means "no row".
     *
     * @param Model|WarrantSchema|string|null $schema The target schema, as a schema
     *   key, a schema instance or class-string, or a model instance or
     *   class-string. Null crosses to no schema at all: another ability of the
     *   schema the rule is written on, over the row and context it already has,
     *   which takes no $row, $with or $as.
     * @param mixed $row The row selector: a key, the target schema's model, a
     *   {@see Ref}, or {@see NoRow} (the default) for an unbound handle.
     * @param array<string, mixed> $with Explicit boundary context for the target
     *   schema, keyed by its context key names; values are scalars or {@see Ref}s.
     * @param string|null $as Names the rows this reference selects, so a
     *   {@see Ref::column()} can tell them apart from a table of the same name
     *   already in scope further out. Requires $row.
     */
    public function ifCan(string $ability, Model|WarrantSchema|string|null $schema = null, mixed $row = new NoRow, array $with = [], ?string $as = null): static
    {
        return $this->addCan('and', $ability, $schema, $row, $with, $as);
    }

    /**
     * {@see ifCan} — AND-joined (an alias, as `andIf` is of `if`).
     *
     * @param array<string, mixed> $with
     */
    public function andIfCan(string $ability, Model|WarrantSchema|string|null $schema = null, mixed $row = new NoRow, array $with = [], ?string $as = null): static
    {
        return $this->addCan('and', $ability, $schema, $row, $with, $as);
    }

    /**
     * {@see ifCan} — OR-joined.
     *
     * @param array<string, mixed> $with
     */
    public function orIfCan(string $ability, Model|WarrantSchema|string|null $schema = null, mixed $row = new NoRow, array $with = [], ?string $as = null): static
    {
        return $this->addCan('or', $ability, $schema, $row, $with, $as);
    }

    /**
     * Add a cross-schema condition check — `check(<predicate> for <handle> [with
     * <map>])` — AND-joined to what precedes it: delegate a question to another
     * schema, answered against that schema's own vocabulary.
     *
     * The predicate is either one condition name, or a closure that receives a bare
     * condition builder to compose a boolean tree — the form to use when a leaf
     * takes parameters. That tree is read against the *target*, so a `can(...)` in
     * it names one of the target's abilities and a nested `check(...)` starts from
     * the target's frame. The closure must add at least one
     * term: unlike a group an empty predicate cannot fold to `false`, because a
     * `check(...)` predicate may not contain a constant, so it throws instead.
     *
     * $schema, $row, $with and $as behave exactly as in {@see ifCan} — with one
     * addition: on a `check(...)` the alias is a name the predicate itself can
     * use, which is how a predicate spanning two frames of one table tells them
     * apart.
     *
     * @param string|Closure(WarrantConditionBuilder):void $predicate
     * @param Model|WarrantSchema|string $schema
     * @param array<string, mixed> $with
     */
    public function ifCheck(string|Closure $predicate, Model|WarrantSchema|string $schema, mixed $row = new NoRow, array $with = [], ?string $as = null): static
    {
        return $this->addCheck('and', $predicate, $schema, $row, $with, $as);
    }

    /**
     * {@see ifCheck} — AND-joined (an alias, as `andIf` is of `if`).
     *
     * @param string|Closure(WarrantConditionBuilder):void $predicate
     * @param array<string, mixed> $with
     */
    public function andIfCheck(string|Closure $predicate, Model|WarrantSchema|string $schema, mixed $row = new NoRow, array $with = [], ?string $as = null): static
    {
        return $this->addCheck('and', $predicate, $schema, $row, $with, $as);
    }

    /**
     * {@see ifCheck} — OR-joined.
     *
     * @param string|Closure(WarrantConditionBuilder):void $predicate
     * @param array<string, mixed> $with
     */
    public function orIfCheck(string|Closure $predicate, Model|WarrantSchema|string $schema, mixed $row = new NoRow, array $with = [], ?string $as = null): static
    {
        return $this->addCheck('or', $predicate, $schema, $row, $with, $as);
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

        return $this->pushTerm($boolean, $node);
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function addRaw(string $boolean, string $expression, array $bindings): static
    {
        return $this->pushTerm($boolean, WarrantParser::parseConditionExpression($expression, $bindings));
    }

    /**
     * Materialize one `can(...)` leaf. The schema reference is normalized to a
     * schema key exactly as {@see \Warrant\Rules\WarrantRuleSet::__construct} does,
     * so the node carries the key the parser would have lexed.
     *
     * @param array<string, mixed> $with
     */
    private function addCan(string $boolean, string $ability, Model|WarrantSchema|string|null $schema, mixed $row, array $with, ?string $as): static
    {
        /* $with and $as travel even though a schema-less reference has no use for
           them, so supplying one is answered by validation rather than dropped. */
        if ($schema === null) {
            return $this->pushTerm($boolean, new CrossSchemaCanNode(null, $ability, contextMap: $with, alias: $as));
        }

        return $this->pushTerm($boolean, new CrossSchemaCanNode(
            Warrant::registry()->resolveSchemaKeyOrFail($schema),
            $ability,
            ! $row instanceof NoRow,
            $row instanceof NoRow ? null : $row,
            $with,
            $as,
        ));
    }

    /**
     * Materialize one `check(...)` leaf. As with {@see addCan} the schema reference
     * is normalized to a schema key.
     *
     * @param string|Closure(WarrantConditionBuilder):void $predicate
     * @param array<string, mixed> $with
     */
    private function addCheck(string $boolean, string|Closure $predicate, Model|WarrantSchema|string $schema, mixed $row, array $with, ?string $as): static
    {
        return $this->pushTerm($boolean, new CrossSchemaConditionNode(
            Warrant::registry()->resolveSchemaKeyOrFail($schema),
            $this->predicate($predicate),
            ! $row instanceof NoRow,
            $row instanceof NoRow ? null : $row,
            $with,
            $as,
        ));
    }

    /**
     * Resolve a `check(...)` predicate to a single expression node. A string is one
     * condition leaf; a closure is a sub-tree built by a bare condition builder,
     * folded with the same precedence as a group.
     *
     * A group folds an empty closure to `false`, but a `check(...)` predicate may
     * not contain a constant boolean — the validator rejects one — so an empty
     * predicate is a builder-level mistake and throws here, where the message can
     * still name the cause.
     *
     * @param string|Closure(WarrantConditionBuilder):void $predicate
     */
    private function predicate(string|Closure $predicate): IBooleanExpressionNode
    {
        if (! $predicate instanceof Closure) {
            return new ConditionNode($predicate);
        }

        $sub = new self;
        $predicate($sub);

        return $sub->buildConditions() ?? throw new LogicException(
            'A check(...) predicate cannot be empty; add at least one condition inside the predicate closure. '
            .'Unlike a group it cannot fall back to false — a check(...) predicate may only ask the target schema\'s conditions.'
        );
    }

    /** Append a materialized operand to the flat term sequence. */
    private function pushTerm(string $boolean, IBooleanExpressionNode $node): static
    {
        $this->terms[] = ['boolean' => $boolean, 'node' => $node];

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
