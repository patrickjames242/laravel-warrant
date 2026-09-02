<?php

use Illuminate\Support\Facades\DB;
use Warrant\Facades\Warrant;
require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

/*
|------------------------------------------------------------------------------
| SQL surface tests — Stage 4: getAbilitiesWithoutTarget()
|------------------------------------------------------------------------------
|
| Unlike filterQuery/selectUserAbilitiesInQuery, the no-target enumeration query
| is never handed back as a builder — runNoTargetAbilityQuery() builds it and
| immediately ->pluck()s the result. So there is no toRawSql() to read; instead
| these tests capture the query the connection actually runs (via its query log)
| and reconstruct the bindings-substituted SQL with the same grammar call
| toRawSql() uses. Both sides are then normalized, as in the other stages.
|
| Shape (from BuildsAccessQueries::runNoTargetAbilityQuery):
|
|   select "ability"
|   from (
|       <one branch per ability, UNION ALL'd when 2+>
|   ) as "available_abilities"
|
| Each branch is `select '<ability>' as "ability" where (<predicate>)`, the
| predicate being the ability compiled with NO target id. That changes only the
| condition leaves:
|   - a *targeted* condition cannot be evaluated without a row, so it is forced
|     false — or, under negation, true.
|   - a *global* condition compiles to its inline where-clause.
|
| Which is why most of what this stage used to assert is now *absent*: a branch
| whose predicate folded to a constant contributes nothing a query could tell us,
| so runNoTargetAbilityQuery decides that ability in PHP and leaves it out of the
| union entirely. No branch ever reads `where (1 = 1)` or `where (1 = 0)` here.
| With every requested ability folded there is no union to build and no query at
| all — assertNoTargetQueryless() covers those, asserting the abilities returned
| instead of SQL that was never sent.
|
| As in Stage 2, the SQLite grammar wraps each UNION arm as `select * from (...)`.
|
*/

/**
 * Run getAbilitiesWithoutTarget for the fixture schema (user role
 * "teacher-role"), capture the single query it executes, and return its
 * bindings-substituted SQL.
 *
 * @param  string|array<int, string>|null  $abilities
 */
function capturedNoTargetSql(string|array|null $abilities): string
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->getAbilitiesWithoutTarget($abilities);

    $connection->disableQueryLog();

    $log = $connection->getQueryLog();

    expect($log)->toHaveCount(1);

    return $connection->getQueryGrammar()->substituteBindingsIntoRawSql(
        $log[0]['query'],
        $connection->prepareBindings($log[0]['bindings']),
    );
}

/**
 * @param  string|array<int, string>|null  $abilities
 */
function assertNoTargetSql(string|array|null $abilities, string $expectedSql): void
{
    expect(normalizeWarrantSql(capturedNoTargetSql($abilities)))->toBe(normalizeWarrantSql($expectedSql));
}

/**
 * Assert the enumeration ran without touching the database — every requested
 * ability folded to a constant, so there was no branch to union — and returned
 * exactly $expectedAbilities.
 *
 * @param  string|array<int, string>|null  $abilities
 * @param  array<int, string>  $expectedAbilities
 */
function assertNoTargetQueryless(string|array|null $abilities, array $expectedAbilities): void
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $held = Warrant::guard(makeWarrantTestUser('teacher-role'))
        ->forSchema((new WarrantTestSchema))
        ->getAbilitiesWithoutTarget($abilities);

    $connection->disableQueryLog();

    expect($connection->getQueryLog())->toBe([]);
    expect($held)->toBe($expectedAbilities);
}

// -- unconditional grant ------------------------------------------------------

it('holds an unconditionally granted ability without a query', function () {
    bindWarrantRules('they can view');

    assertNoTargetQueryless('view', ['view']);
});

// -- row conditions have no row, so they are forced ----------------------

it('forces a row condition false without a target, and asks nothing', function () {
    bindWarrantRules('if is_teacher they can view');

    assertNoTargetQueryless('view', []);
});

it('forces a negated row condition true without a target, and asks nothing', function () {
    bindWarrantRules('if not is_teacher they can view');

    assertNoTargetQueryless('view', ['view']);
});

it('asks nothing when no rule grants the ability', function () {
    bindWarrantRules('they can update');

    assertNoTargetQueryless('view', []);
});

it('asks nothing for an unconditional cannot', function () {
    bindWarrantRules("they can view\nthey cannot view");

    assertNoTargetQueryless('view', []);
});

// -- global positive conditions are inlined -----------------------

it('inlines a positive global condition as a correlated predicate', function () {
    bindWarrantRules('if is_advisor they can view');

    assertNoTargetSql('view', <<<SQL
        select "ability"
        from (
            select 'view' as "ability"
            where (
                'advisor' = 'teacher-role'
            )
        ) as "available_abilities"
    SQL);
});

// -- multiple explicit abilities (UNION ALL) ----------------------------------

it('UNION ALLs one wrapped branch per explicit ability', function () {
    bindWarrantRules("if is_advisor they can view\nif is_advisor they can update");

    assertNoTargetSql(['view', 'update'], <<<SQL
        select "ability"
        from (
            select * from (select 'view' as "ability" where ('advisor' = 'teacher-role'))
            union all
            select * from (select 'update' as "ability" where ('advisor' = 'teacher-role'))
        ) as "available_abilities"
    SQL);
});

it('leaves a folded ability out of the union, keeping only what SQL can decide', function () {
    bindWarrantRules("they can update\nif is_advisor they can view\nif is_teacher they can publish");

    // `update` folded true and `publish` folded false (no row to test), so only
    // `view` — gated on a *global* condition — is left to ask about.
    assertNoTargetSql(['view', 'update', 'publish'], <<<SQL
        select "ability"
        from (
            select 'view' as "ability" where ('advisor' = 'teacher-role')
        ) as "available_abilities"
    SQL);
});

it('merges folded and queried abilities back into the requested order', function () {
    bindWarrantRules("they can update\nif is_advisor they can view");

    $held = Warrant::guard(makeWarrantTestUser('advisor'))
        ->forSchema((new WarrantTestSchema))
        ->getAbilitiesWithoutTarget(['view', 'update']);

    // `update` folded (decided in PHP), `view` came back from the union — the
    // caller still sees them in the order it asked for.
    expect($held)->toBe(['view', 'update']);
});

// -- enumeration (all declared abilities) -------------------------------------

it('enumerates every declared ability in declaration order', function () {
    bindWarrantRules('if is_advisor they can *');

    assertNoTargetSql(null, <<<SQL
        select "ability"
        from (
            select * from (select 'create' as "ability" where ('advisor' = 'teacher-role'))
            union all
            select * from (select 'publish' as "ability" where ('advisor' = 'teacher-role'))
            union all
            select * from (select 'archive' as "ability" where ('advisor' = 'teacher-role'))
            union all
            select * from (select 'view' as "ability" where ('advisor' = 'teacher-role'))
            union all
            select * from (select 'update' as "ability" where ('advisor' = 'teacher-role'))
        ) as "available_abilities"
    SQL);
});

it('enumerates in declaration order without a query when every ability folds', function () {
    bindWarrantRules('they can *');

    assertNoTargetQueryless(null, ['create', 'publish', 'archive', 'view', 'update']);
});
