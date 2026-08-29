<?php

namespace Warrant\Compiler;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * A boolean where clause tree, built before any SQL is emitted.
 *
 * Operands are added one at a time under a connector, and may be a literal
 * `bool`, a {@see Builder} that a condition augmented (where clauses only), or
 * another node — which is what makes this a tree. Nothing touches a query
 * builder until {@see buildWhereClause}, so {@see simplify} gets to collapse
 * constants and drop the parentheses that a direct-to-builder walk is forced to
 * emit.
 *
 * A mixed list is read with SQL precedence — `and` binds tighter than `or`, so
 * `a and b or c and d` is `(a and b) or (c and d)`. Simplifying starts by
 * splitting the list on that rule, which turns this authoring surface into a
 * tree where every group has one connector, before any simplification runs; the
 * mixed list is never the thing being simplified.
 *
 * What gets simplified, working from the leaves up:
 *   - a `false` inside an `and` (or a `true` inside an `or`) decides the whole
 *     group, and a `true` inside an `and` (or a `false` inside an `or`) changes
 *     nothing and is dropped;
 *   - a leaf builder that added no where clause matched everything, so it *is*
 *     `true` (`false` when negated) and simplifies away with the rest;
 *   - a group left holding one operand is that operand — no wrapper, no parens;
 *   - an unnegated child sharing its parent's connector is merged into it, since
 *     `a and (b and c)` is `a and b and c`;
 *   - a leaf holding exactly one where clause is lifted into its parent rather
 *     than nested in a group of its own (see {@see writeOperandsInto}).
 *
 * An empty node is `true` — the value that changes nothing when anded, which is
 * how a freshly made group is used while operands are still being collected.
 *
 * The examples throughout write `a`, `b`, `c` for leaf builders holding one
 * where clause each, `!a` for a negated operand, and `and[x, y]` / `or[x, y]`
 * for a group with that connector. A literal operand is written out in full as
 * `true` or `false`.
 */
final class CompiledWhereClauseNode
{
    /**
     * The operands in the order they were added. The first one's connector is
     * ignored, the same way the query builder ignores a leading connector.
     *
     * @var list<CompiledWhereClauseOperand>
     */
    private array $operands = [];

    /**
     * The one connector every operand attaches under. Set only on a group built
     * during {@see simplify}; null while a node is still being assembled, where
     * the connector is per-operand and precedence has yet to be resolved.
     */
    private ?string $operator = null;

    /**
     * Add an operand under `and`.
     *
     * $negated inverts just this operand — see {@see CompiledWhereClauseOperand}.
     * Mutates and returns `$this` for chaining.
     *
     *     (new Node)->addAnd($a)->addAnd($b)                 // a and b
     *     (new Node)->addAnd($a)->addAnd($b, negated: true)  // a and !b
     *     (new Node)->addAnd($a)->addAnd(false)              // a and false
     *     (new Node)->addAnd($a)->addAnd($child)             // a and (child)
     */
    public function addAnd(bool|Builder|self $operand, bool $negated = false): self
    {
        return $this->addOperand(new CompiledWhereClauseOperand('and', $operand, $negated));
    }

    /**
     * Add an operand under `or`. See {@see addAnd} for $negated.
     *
     *     (new Node)->addAnd($a)->addAnd($b)->addOr($c)   // a and b or c
     */
    public function addOr(bool|Builder|self $operand, bool $negated = false): self
    {
        return $this->addOperand(new CompiledWhereClauseOperand('or', $operand, $negated));
    }

    /**
     * Append the operand, rejecting a node added to itself — which would make
     * the tree cyclic and every walk over it non-terminating.
     *
     *     $node->addAnd($node)   // throws
     */
    private function addOperand(CompiledWhereClauseOperand $operand): self
    {
        if ($operand->value === $this) {
            throw new InvalidArgumentException('A where clause node cannot contain itself.');
        }

        $this->operands[] = $operand;

        return $this;
    }

    // -- simplifying -----------------------------------------------------------

    /**
     * Collapse the tree to either a literal decision or a simplified node.
     *
     * Simplified means: one connector that every operand attaches under, no
     * `bool` operands left, no operand that is an empty leaf builder, no
     * single-operand group and no child sharing its parent's connector, and
     * every child simplified in turn. Negation survives only on leaves.
     *
     *     a and true and b     ->  and[a, b]   the `true` is dropped
     *     a and false          ->  false       the whole node decided
     *     a or true            ->  true
     *     true and true        ->  true
     *     a                    ->  and[a]      a lone leaf still needs a group
     *     or[a, b]  (alone)    ->  or[a, b]    a lone child is returned as-is
     *     a and b or c and d   ->  or[and[a, b], and[c, d]]
     */
    public function simplify(): bool|self
    {
        if ($this->operands === []) {
            return true;
        }

        // The node is the `or` of its and-groups.
        $survivors = [];

        foreach ($this->splitIntoAndGroups() as $andGroup) {
            $simplified = $this->simplifyAndGroup($andGroup);

            if ($simplified === true) {
                return true;    // true or anything.
            }

            if ($simplified === false) {
                continue;       // false changes nothing in an or.
            }

            $survivors = self::appendOperand($survivors, 'or', $simplified);
        }

        if ($survivors === []) {
            return false;
        }

        if (count($survivors) > 1) {
            return self::makeGroup('or', $survivors);
        }

        // A lone unnegated child node is already simplified; a lone leaf needs a
        // group to carry it (and its negation).
        return $survivors[0]->value instanceof self && ! $survivors[0]->negated
            ? $survivors[0]->value
            : self::makeGroup('and', [$survivors[0]]);
    }

    /**
     * Simplify one and-group to a single operand — or to a literal, when the
     * group decided its own outcome.
     *
     *     [a, true, b]     ->  and[a, b]    the `true` is dropped
     *     [a, false]       ->  false
     *     [true, true]     ->  true         nothing left to and together
     *     [a]              ->  a            returned bare, no group built
     *     [<empty>, a]     ->  a            a builder with no where clause is true
     *     [!<empty>, a]    ->  false        and negated, it is false
     *     [!or[x, y]]      ->  and[!x, !y]  the negation is pushed inwards
     *
     * @param  list<CompiledWhereClauseOperand>  $andGroup
     */
    private function simplifyAndGroup(array $andGroup): bool|CompiledWhereClauseOperand
    {
        $survivors = [];

        foreach ($andGroup as $operand) {
            $value = $operand->value;

            // Resolve the operand, pushing its negation inwards: a `bool` flips,
            // a child node is negated as a whole, and a leaf builder keeps the
            // flag (it emits as `not (...)`). A builder holding no where clause
            // matched every row, so it *is* true.
            if (is_bool($value)) {
                $simplified = $operand->negated ? ! $value : $value;
            } elseif ($value instanceof Builder) {
                $simplified = $value->wheres === [] ? ! $operand->negated : $operand;
            } else {
                $inner = $value->simplify();

                $simplified = is_bool($inner)
                    ? ($operand->negated ? ! $inner : $inner)
                    : new CompiledWhereClauseOperand(
                        $operand->boolean,
                        $operand->negated ? $inner->negatedCopy() : $inner,
                    );
            }

            if ($simplified === false) {
                return false;   // false and anything.
            }

            if ($simplified === true) {
                continue;       // true changes nothing in an and.
            }

            $survivors = self::appendOperand($survivors, 'and', $simplified);
        }

        return match (count($survivors)) {
            0 => true,
            1 => $survivors[0],
            default => new CompiledWhereClauseOperand('and', self::makeGroup('and', $survivors)),
        };
    }

    /**
     * Split the operand list into and-groups, resolving the precedence of a
     * mixed list: a new group starts at each operand added under `or`, so the
     * node is the `or` of its groups and each group the `and` of its operands.
     * The first operand's connector is ignored, so a list is always at least one
     * group.
     *
     *     a and b or c and d   ->  [[a, b], [c, d]]
     *     a or b               ->  [[a], [b]]
     *     a and b              ->  [[a, b]]
     *     or a or b            ->  [[a], [b]]   the leading `or` is ignored
     *
     * @return list<list<CompiledWhereClauseOperand>>
     */
    private function splitIntoAndGroups(): array
    {
        $groups = [];
        $group = [];

        foreach ($this->operands as $index => $operand) {
            if ($index > 0 && $operand->boolean === 'or') {
                $groups[] = $group;
                $group = [];
            }

            $group[] = $operand;
        }

        return [...$groups, $group];
    }

    /**
     * Append a simplified operand to a group's list — merging an unnegated child
     * that shares the group's connector into it instead, since
     * `a and (b and c)` is `a and b and c` and the inner group would only add
     * parentheses. A child under the other connector, or a negated one, is kept
     * whole — its parentheses are load-bearing.
     *
     *     [a] + b            under and  ->  [a, b]
     *     [a] + and[b, c]    under and  ->  [a, b, c]        merged
     *     [a] + or[b, c]     under and  ->  [a, or[b, c]]    kept whole
     *     [a] + !and[b, c]   under and  ->  [a, !and[b, c]]  kept whole
     *     [a] + and[b, c]    under or   ->  [a, and[b, c]]   kept whole
     *
     * @param  list<CompiledWhereClauseOperand>  $list
     * @return list<CompiledWhereClauseOperand>
     */
    private static function appendOperand(array $list, string $operator, CompiledWhereClauseOperand $operand): array
    {
        $value = $operand->value;

        if ($value instanceof self && ! $operand->negated && $value->operator === $operator) {
            foreach ($value->operands as $child) {
                $list[] = $child;
            }

            return $list;
        }

        $list[] = $operand;

        return $list;
    }

    /**
     * Negate a simplified node by pushing the `not` inwards: swap `and` for `or`
     * and negate every operand, which is cheaper than wrapping the whole group
     * and keeps negation on the leaves where it emits as `not (...)`. Constants
     * are already gone by this point, so an operand is either a leaf (flip its
     * flag) or a child node (recurse into it).
     *
     *     and[a, !b]           ->  or[!a, b]
     *     or[a, and[b, c]]     ->  and[!a, or[!b, !c]]
     */
    private function negatedCopy(): self
    {
        return self::makeGroup($this->operator === 'and' ? 'or' : 'and', array_map(
            fn (CompiledWhereClauseOperand $operand): CompiledWhereClauseOperand => $operand->value instanceof self
                ? new CompiledWhereClauseOperand($operand->boolean, $operand->value->negatedCopy())
                : $operand->flipNegation(),
            $this->operands,
        ));
    }

    /**
     * Build a group: every operand re-attached under one connector, which is
     * what makes its connector authoritative. Only {@see simplify} and the
     * helpers it calls make these.
     *
     *     makeGroup('or', [a (and), b (and)])  ->  or[a (or), b (or)]
     *
     * @param  list<CompiledWhereClauseOperand>  $operands
     */
    private static function makeGroup(string $operator, array $operands): self
    {
        $node = new self;
        $node->operator = $operator;

        foreach ($operands as $operand) {
            $node->operands[] = $operand->under($operator);
        }

        return $node;
    }

    // -- emitting --------------------------------------------------------------

    /**
     * Simplify, then write whatever survives into a fresh query built off $host.
     *
     * Returns the literal `true`/`false` when the tree decided the outcome on
     * its own; what a literal looks like in SQL is the caller's choice, not this
     * node's, since only the caller knows whether it is emitting a whole
     * predicate or a fragment of one.
     *
     *     a and true and b  ->  Builder: `a = 1 and b = 2`
     *     a and (b or c)    ->  Builder: `a = 1 and (b = 2 or c = 3)`
     *     a and false       ->  false, and no query is built at all
     */
    public function buildWhereClause(Builder $host): bool|Builder
    {
        $simplified = $this->simplify();

        if (is_bool($simplified)) {
            return $simplified;
        }

        // No wrapper at the root: the returned query *is* the outermost group.
        $out = $host->newQuery();
        $simplified->writeOperandsInto($out);

        return $out;
    }

    /**
     * Write this group's operands into $parent, which stands in for the group
     * itself — the first attaches under `and` (a leading connector is dropped
     * anyway), the rest under the group's connector.
     *
     * Writing `and[a, or[b, c], !d, e]` into an empty $parent leaves it holding:
     *
     *     a = 1                      lifted, the leaf held one clause
     *     and (b = 2 or c = 3)       a child group, so parenthesised
     *     and not (d = 4)            negated, so the not binds to a group
     *     and (e = 5 and f = 6)      the leaf held two clauses, so wrapped
     */
    private function writeOperandsInto(Builder $parent): void
    {
        foreach ($this->operands as $index => $operand) {
            $boolean = $index === 0 ? 'and' : $this->operator;
            $value = $operand->value;

            if ($value instanceof self) {
                $parent->where(
                    fn (Builder $group) => $value->writeOperandsInto($group),
                    null,
                    null,
                    $boolean,
                );

                continue;
            }

            // A leaf holding exactly one where clause needs no group of its own,
            // so lift the clause straight into the parent under this connector.
            // That is addNestedWhereQuery() without the nested wrapper, and with
            // a single clause its bindings are trivially still in order. This is
            // what keeps a condition from being wrapped in parentheses it never
            // needed — `and (exists (...))` stays `and exists (...)`. A negated
            // leaf still nests, since the `not` has to bind to a group.
            if (! $operand->negated && count($value->wheres) === 1) {
                $parent->wheres[] = ['boolean' => $boolean] + $value->wheres[0];
                $parent->addBinding($value->getRawBindings()['where'], 'where');

                continue;
            }

            $parent->addNestedWhereQuery($value, $operand->negated ? "{$boolean} not" : $boolean);
        }
    }
}
