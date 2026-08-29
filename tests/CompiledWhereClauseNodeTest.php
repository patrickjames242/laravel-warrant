<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Database\Query\Builder;
use Warrant\Compiler\CompiledWhereClauseNode;

/*
|------------------------------------------------------------------------------
| CompiledWhereClauseNode — the where clause tree
|------------------------------------------------------------------------------
|
| These test the node in isolation: it is not yet wired into RuleSetCompiler.
| Leaves are plain query builders carrying nothing but where clauses, exactly as
| a condition would hand them over.
|
| Each assertion goes through the real emit path — build the where clause off a
| host query, splice the result in with addNestedWhereQuery() (what the guard
| does today), and compare the bindings-substituted SQL. normalizeWarrantSql()
| collapses doubled parentheses `((E))` -> `(E)`, so an assertion here is about
| genuine structure, never formatting.
|
*/

/**
 * A leaf: a where-only query, the shape a condition returns.
 *
 * @param  array<int, mixed>  $bindings
 */
function nodeLeaf(string $sql, array $bindings = []): Builder
{
    return warrantTestQuery()->newQuery()->whereRaw($sql, $bindings);
}

/**
 * A condition that added no where clause at all.
 */
function nodeEmptyLeaf(): Builder
{
    return warrantTestQuery()->newQuery();
}

/**
 * Build $node's where clause and return either the literal it simplified to, or
 * the normalized SQL of a host query with that where clause spliced in.
 */
function nodeSql(CompiledWhereClauseNode $node): string|bool
{
    $host = warrantTestQuery();
    $result = $node->buildWhereClause($host);

    if (is_bool($result)) {
        return $result;
    }

    return normalizeWarrantSql($host->addNestedWhereQuery($result)->toRawSql());
}

function nodeExpect(CompiledWhereClauseNode $node, string $expected): void
{
    expect(nodeSql($node))->toBe(normalizeWarrantSql($expected));
}

// -- constants ----------------------------------------------------------------

it('folds an empty node to true', function () {
    expect(nodeSql(new CompiledWhereClauseNode))->toBeTrue();
});

it('absorbs a false operand in an and', function () {
    expect(nodeSql(
        (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'))->addAnd(false),
    ))->toBeFalse();
});

it('absorbs a true operand in an or', function () {
    expect(nodeSql(
        (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'))->addOr(true),
    ))->toBeTrue();
});

it('drops a true operand from an and', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd(true)->addAnd(nodeLeaf('a = 1'))->addAnd(true),
        'select * from "course_sections" where (a = 1)',
    );
});

it('drops a false operand from an or', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd(false)->addOr(nodeLeaf('a = 1'))->addOr(false),
        'select * from "course_sections" where (a = 1)',
    );
});

it('folds an all-true and to true, and an all-false or to false', function () {
    expect(nodeSql((new CompiledWhereClauseNode)->addAnd(true)->addAnd(true)))->toBeTrue();
    expect(nodeSql((new CompiledWhereClauseNode)->addAnd(false)->addOr(false)))->toBeFalse();
});

it('flips a negated bool operand', function () {
    expect(nodeSql((new CompiledWhereClauseNode)->addAnd(false, negated: true)))->toBeTrue();
    expect(nodeSql((new CompiledWhereClauseNode)->addAnd(true, negated: true)))->toBeFalse();
});

it('treats a leaf that added no where clause as true', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd(nodeEmptyLeaf())->addAnd(nodeLeaf('a = 1')),
        'select * from "course_sections" where (a = 1)',
    );
});

it('treats a negated empty leaf as false', function () {
    expect(nodeSql(
        (new CompiledWhereClauseNode)->addAnd(nodeEmptyLeaf(), negated: true)->addAnd(nodeLeaf('a = 1')),
    ))->toBeFalse();
});

it('folds a child node that collapsed to a constant', function () {
    $child = (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'))->addAnd(false);

    expect(nodeSql((new CompiledWhereClauseNode)->addAnd($child)->addAnd(nodeLeaf('b = 2'))))->toBeFalse();
});

// -- precedence ---------------------------------------------------------------

it('reads a mixed list as or of and-runs', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)
            ->addAnd(nodeLeaf('a = 1'))
            ->addAnd(nodeLeaf('b = 2'))
            ->addOr(nodeLeaf('c = 3'))
            ->addAnd(nodeLeaf('d = 4')),
        'select * from "course_sections" where (
            (a = 1 and b = 2) or (c = 3 and d = 4)
        )',
    );
});

it('ignores the leading connector', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)->addOr(nodeLeaf('a = 1'))->addOr(nodeLeaf('b = 2')),
        'select * from "course_sections" where (a = 1 or b = 2)',
    );
});

it('folds a whole and-run away without taking the or with it', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)
            ->addAnd(nodeLeaf('a = 1'))
            ->addAnd(false)
            ->addOr(nodeLeaf('c = 3')),
        'select * from "course_sections" where (c = 3)',
    );
});

// -- structure ----------------------------------------------------------------

it('emits a single operand without a group of its own', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1')),
        'select * from "course_sections" where (a = 1)',
    );
});

it('splices a child sharing its parent operator', function () {
    $inner = (new CompiledWhereClauseNode)->addAnd(nodeLeaf('b = 2'))->addAnd(nodeLeaf('c = 3'));

    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'))->addAnd($inner),
        'select * from "course_sections" where (a = 1 and b = 2 and c = 3)',
    );
});

it('keeps a child whose operator differs from its parent', function () {
    $inner = (new CompiledWhereClauseNode)->addAnd(nodeLeaf('b = 2'))->addOr(nodeLeaf('c = 3'));

    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'))->addAnd($inner),
        'select * from "course_sections" where (a = 1 and (b = 2 or c = 3))',
    );
});

it('lifts a single-clause leaf instead of wrapping it', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)
            ->addAnd(nodeLeaf('exists (select 1 from "enrollments")'))
            ->addAnd(nodeLeaf('b = 2')),
        'select * from "course_sections" where (
            exists (select 1 from "enrollments") and b = 2
        )',
    );
});

it('wraps a leaf that holds more than one clause', function () {
    $leaf = warrantTestQuery()->newQuery()->whereRaw('a = ?', ['x'])->whereRaw('b = ?', ['y']);

    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd($leaf)->addAnd(nodeLeaf('c = 3')),
        "select * from \"course_sections\" where (
            (a = 'x' and b = 'y') and c = 3
        )",
    );
});

// -- negation -----------------------------------------------------------------

it('emits a negated leaf as a not group', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'), negated: true)->addAnd(nodeLeaf('b = 2')),
        'select * from "course_sections" where (not (a = 1) and b = 2)',
    );
});

it('pushes a negated child node down by de morgan', function () {
    $inner = (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'))->addOr(nodeLeaf('b = 2'));

    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd($inner, negated: true),
        'select * from "course_sections" where (not (a = 1) and not (b = 2))',
    );
});

it('cancels a negation pushed through two levels', function () {
    $leaf = (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'), negated: true)->addAnd(nodeLeaf('b = 2'));

    nodeExpect(
        (new CompiledWhereClauseNode)->addAnd($leaf, negated: true),
        'select * from "course_sections" where (a = 1 or not (b = 2))',
    );
});

it('folds constants inside a negated child', function () {
    $inner = (new CompiledWhereClauseNode)->addAnd(nodeLeaf('a = 1'))->addOr(true);

    expect(nodeSql((new CompiledWhereClauseNode)->addAnd($inner, negated: true)))->toBeFalse();
});

// -- bindings -----------------------------------------------------------------

it('keeps bindings in order when lifting leaves', function () {
    nodeExpect(
        (new CompiledWhereClauseNode)
            ->addAnd(nodeLeaf('a = ?', ['one']))
            ->addAnd(nodeLeaf('b = ?', ['two']))
            ->addAnd(nodeLeaf('c = ?', ['three'])),
        "select * from \"course_sections\" where (
            a = 'one' and b = 'two' and c = 'three'
        )",
    );
});

it('keeps bindings in order across lifted, wrapped and nested operands', function () {
    $wrapped = warrantTestQuery()->newQuery()->whereRaw('b = ?', ['two'])->whereRaw('c = ?', ['three']);
    $inner = (new CompiledWhereClauseNode)->addAnd(nodeLeaf('d = ?', ['four']))->addOr(nodeLeaf('e = ?', ['five']));

    nodeExpect(
        (new CompiledWhereClauseNode)
            ->addAnd(nodeLeaf('a = ?', ['one']))
            ->addAnd($wrapped)
            ->addAnd($inner)
            ->addAnd(nodeLeaf('f = ?', ['six']), negated: true),
        "select * from \"course_sections\" where (
            a = 'one'
            and (b = 'two' and c = 'three')
            and (d = 'four' or e = 'five')
            and not (f = 'six')
        )",
    );
});

it('rejects a node added to itself', function () {
    $node = new CompiledWhereClauseNode;

    expect(fn () => $node->addAnd($node))->toThrow(InvalidArgumentException::class, 'cannot contain itself');
});
