<?php

use Warrant\DSL\Compiling\AliasScope;
require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| AliasScope
|------------------------------------------------------------------------------
|
| The map of DSL name -> SQL qualifier that a compile carries down through each
| cross-schema hop, and against which a @column reference is resolved. Two things
| are worth locking in: which names survive a descent (a can(...) hop starts a
| fresh namespace, a check(...) hop inherits the enclosing one), and the
| difference between a name bound to null — known, but with no row in scope — and
| a name nobody bound at all, which is an author's mistake.
|
*/

// -- the root frame -----------------------------------------------------------

it('binds the compiled schema key to the query qualifier, and makes it current', function () {
    $scope = AliasScope::root('docs', 'docs');

    expect($scope->current)->toBe('docs');
    expect($scope->resolve('docs'))->toBe('docs');
    expect($scope->resolve(null))->toBe('docs');
    expect($scope->names())->toBe(['docs']);
});

it('keeps the schema key pointing at the host query alias, not the table', function () {
    $scope = AliasScope::root('docs', 'd');

    expect($scope->resolve('docs'))->toBe('d');
    expect($scope->resolve(null))->toBe('d');
});

// -- descending into another rule set (a can(...) hop) ------------------------

it('starts a fresh namespace for another rule set, dropping the caller names', function () {
    $inner = AliasScope::root('docs', 'docs')->enteringRuleSet('folders', 'folders');

    expect($inner->current)->toBe('folders');
    expect($inner->resolve('folders'))->toBe('folders');
    expect($inner->names())->toBe(['folders']);

    /* The target's rules were written without knowing who reached them, so the
       caller's table is deliberately unnameable there. */
    expect(fn () => $inner->resolve('docs'))
        ->toThrow(InvalidArgumentException::class, 'names [docs], which is not in scope');
});

it('binds a re-entered schema key to the new frame, not the outer one', function () {
    $inner = AliasScope::root('docs', 'docs')->enteringRuleSet('docs', 'd2');

    expect($inner->resolve('docs'))->toBe('d2');
    expect($inner->current)->toBe('d2');
});

// -- descending into an inline predicate (a check(...) hop) -------------------

it('keeps the enclosing names in scope for a predicate, so it can correlate out', function () {
    $inner = AliasScope::root('docs', 'docs')->enteringPredicate('folders', null, 'folders');

    expect($inner->current)->toBe('folders');
    expect($inner->resolve('folders'))->toBe('folders');
    expect($inner->resolve('docs'))->toBe('docs');
    expect($inner->names())->toBe(['docs', 'folders']);
});

it('shadows an outer name with the closer frame when the hop is unaliased', function () {
    $inner = AliasScope::root('docs', 'docs')->enteringPredicate('docs', null, 'docs');

    // The closest binding wins, exactly as in SQL — the outer row is unreachable.
    expect($inner->resolve('docs'))->toBe('docs');
    expect($inner->names())->toBe(['docs']);
});

it('leaves an outer name reachable when the closer frame is aliased', function () {
    $inner = AliasScope::root('docs', 'docs')->enteringPredicate('docs', 'd2', 'd2');

    expect($inner->resolve('d2'))->toBe('d2');
    expect($inner->resolve('docs'))->toBe('docs');
    expect($inner->resolve(null))->toBe('d2');
    expect($inner->names())->toBe(['docs', 'd2']);
});

// -- a row that is not in scope ----------------------------------------------

it('distinguishes a name bound to no row from a name nobody bound', function () {
    $scope = AliasScope::root('docs', null);

    expect($scope->current)->toBeNull();
    expect($scope->resolve('docs'))->toBeNull();
    expect($scope->resolve(null))->toBeNull();
    expect($scope->has('docs'))->toBeTrue();

    expect($scope->has('folders'))->toBeFalse();
    expect(fn () => $scope->resolve('folders'))->toThrow(InvalidArgumentException::class);
});

it('lists the names in scope when a reference names none of them', function () {
    $scope = AliasScope::root('docs', 'docs')->enteringPredicate('folders', 'f', 'f');

    expect(fn () => $scope->resolve('nope'))
        ->toThrow(InvalidArgumentException::class, 'the names in scope are [docs, f].');
});

it('names nothing at all for a schema with no rows', function () {
    $scope = AliasScope::none();

    expect($scope->current)->toBeNull();
    expect($scope->names())->toBe([]);
    expect($scope->has('caps'))->toBeFalse();

    // A capability schema has no table, so naming it is a mistake, not a row out
    // of scope — the message says so rather than reporting a bound-but-null name.
    expect(fn () => $scope->resolve('caps'))
        ->toThrow(InvalidArgumentException::class, 'no table is in scope at this point.');
});

// -- identifiers, as distinct from names --------------------------------------

/*
| A name is the author's, an identifier is the compiler's. Every frame in one
| query needs its own identifier, or a predicate could not name one frame from
| inside another — so the author's word is the base and a suffix is added only
| when that identifier is already spoken for.
*/

it('hands back the name asked for when nothing else has it', function () {
    expect(AliasScope::root('docs', 'docs')->freeQualifier('folders'))->toBe('folders');
});

it('suffixes the name asked for when it is already spoken for', function () {
    expect(AliasScope::root('docs', 'docs')->freeQualifier('docs'))->toBe('docs_1');
});

it('keeps the author name as the base, whatever the suffix', function () {
    $scope = AliasScope::root('docs', 'docs')->enteringPredicate('docs', 'd2', 'd2');

    expect($scope->freeQualifier('d2'))->toBe('d2_1');
});

it('walks past a suffix that is itself taken', function () {
    $scope = AliasScope::root('docs', 'docs')
        ->enteringPredicate('docs', 'docs_1', 'docs_1')
        ->enteringPredicate('docs', 'docs_2', 'docs_2');

    expect($scope->freeQualifier('docs'))->toBe('docs_3');
});

it('keeps the caller identifiers when a rule set drops the caller names', function () {
    /* The namespace resets for another schema's text, but its rows are selected
       into the same query, so the identifiers are still taken. */
    $inner = AliasScope::root('docs', 'docs')->enteringRuleSet('docs', 'd2');

    expect($inner->names())->toBe(['docs']);
    expect($inner->usedQualifiers)->toBe(['docs', 'd2']);
    expect($inner->freeQualifier('docs'))->toBe('docs_1');
});

it('records nothing for a frame with no rows in scope', function () {
    $unbound = AliasScope::root('docs', 'docs')->enteringRuleSet('folders', null);

    expect($unbound->usedQualifiers)->toBe(['docs']);
});

it('leaves sibling descents unaware of each other', function () {
    // Each branch derives its own scope, so two hops side by side both get the
    // plain table name — they are separate subqueries and cannot collide.
    $outer = AliasScope::root('docs', 'docs');

    $outer->enteringRuleSet('folders', 'folders');

    expect($outer->enteringRuleSet('folders', 'folders')->usedQualifiers)
        ->toBe(['docs', 'folders']);
    expect($outer->freeQualifier('folders'))->toBe('folders');
});

// -- immutability -------------------------------------------------------------

it('never disturbs the scope a descent was derived from', function () {
    $outer = AliasScope::root('docs', 'docs');

    $outer->enteringRuleSet('folders', 'folders');
    $outer->enteringPredicate('docs', 'd2', 'd2');

    expect($outer->names())->toBe(['docs']);
    expect($outer->current)->toBe('docs');
});
