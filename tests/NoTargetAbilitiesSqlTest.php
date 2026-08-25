<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Support\Facades\DB;

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
| predicate being compileAbility() run with NO target id. That changes only the
| condition leaves:
|   - a *targeted* condition cannot be evaluated without a row, so it is forced
|     false — `1 = 0` — or, under negation, true — `1 = 1`.
|   - a *global* condition compiles to its inline where-clause.
| The grant/deny formula and unconditional `1 = 1` / `1 = 0` shapes are unchanged.
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

    (new WarrantTestSchema)->getAbilitiesWithoutTarget(makeWarrantTestUser('teacher-role'), $abilities);

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

// -- unconditional grant ------------------------------------------------------

it('selects an always-true branch for an unconditional grant', function () {
    bindWarrantRules('they can view');

    assertNoTargetSql('view', <<<SQL
        select "ability"
        from (
            select 'view' as "ability" where (1 = 1)
        ) as "available_abilities"
    SQL);
});

// -- row conditions have no row, so they are forced ----------------------

it('forces a row condition false without a target', function () {
    bindWarrantRules('if is_teacher they can view');

    assertNoTargetSql('view', <<<SQL
        select "ability"
        from (
            select 'view' as "ability" where (1 = 0)
        ) as "available_abilities"
    SQL);
});

it('forces a negated row condition true without a target', function () {
    bindWarrantRules('if not is_teacher they can view');

    assertNoTargetSql('view', <<<SQL
        select "ability"
        from (
            select 'view' as "ability" where (1 = 1)
        ) as "available_abilities"
    SQL);
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
    bindWarrantRules('they can *');

    assertNoTargetSql(['view', 'update'], <<<SQL
        select "ability"
        from (
            select * from (select 'view' as "ability" where (1 = 1))
            union all
            select * from (select 'update' as "ability" where (1 = 1))
        ) as "available_abilities"
    SQL);
});

// -- enumeration (all declared abilities) -------------------------------------

it('enumerates every declared ability in declaration order', function () {
    bindWarrantRules('they can *');

    assertNoTargetSql(null, <<<SQL
        select "ability"
        from (
            select * from (select 'create' as "ability" where (1 = 1))
            union all
            select * from (select 'publish' as "ability" where (1 = 1))
            union all
            select * from (select 'archive' as "ability" where (1 = 1))
            union all
            select * from (select 'view' as "ability" where (1 = 1))
            union all
            select * from (select 'update' as "ability" where (1 = 1))
        ) as "available_abilities"
    SQL);
});
