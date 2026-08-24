<?php

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| SQL surface tests — Stage 2: selectUserAbilitiesInQuery()
|------------------------------------------------------------------------------
|
| selectUserAbilitiesInQuery() adds a per-row JSON column listing the abilities
| the user holds for that row. Shape (from BuildsAccessQueries):
|
|   select *, (
|       select coalesce(json_group_array("ability"), json_array())   -- sqlite driver
|       from (
|           <one branch per ability, UNION ALL'd>
|       ) as "available_abilities"
|   ) as "<key>"
|   from "<table>"
|
| Each branch is `select '<ability>' as "ability" where (<ability predicate>)`,
| the predicate being exactly what filterQuery compiles per ability. With two or
| more abilities the branches are UNION ALL'd; the SQLite grammar wraps each
| union arm as `select * from (<branch>)` (its wrapUnion form). With one ability
| there is no union, so the single branch is used directly.
|
| Notes:
|   - the fixture connection is sqlite, so the aggregate is json_group_array;
|     the pgsql/mysql variants are a driver switch not exercised here.
|   - abilities default (onlyAbilities = null) to the schema's declared,
|     declared set, in declaration order: create, publish, archive, view, update.
|   - bindings are substituted inline via toRawSql(); the fixture role is
|     "teacher-role", so isTeacher's target predicate reads
|     `course_sections.id = 'teacher:teacher-role'`.
|
*/

/**
 * Build selectUserAbilitiesInQuery for the fixture schema and assert its
 * normalized, bindings-substituted SQL.
 *
 * @param  array<int, string>|null  $onlyAbilities
 */
function assertWarrantAbilitiesSql(
    string $expectedSql,
    ?array $onlyAbilities = null,
    string $selectedAbilitiesKey = 'abilities',
): void {
    $sql = (new WarrantTestSchema)->selectUserAbilitiesInQuery(
        makeWarrantTestUser('teacher-role'),
        warrantTestQuery(),
        'course_sections.id',
        $selectedAbilitiesKey,
        $onlyAbilities,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql($expectedSql));
}

// -- single ability -----------------------------------------------------------

it('wraps a single ability branch without a union', function () {
    bindWarrantRules('if is_teacher they can view');

    assertWarrantAbilitiesSql(<<<SQL
        select *, (
            select coalesce(json_group_array("ability"), json_array())
            from (
                select 'view' as "ability"
                where (
                    course_sections.id = 'teacher:teacher-role'
                )
            ) as "available_abilities"
        ) as "abilities"
        from "course_sections"
    SQL, onlyAbilities: ['view']);
});

it('emits an always-true branch for an unconditional grant', function () {
    bindWarrantRules('they can view');

    assertWarrantAbilitiesSql(<<<SQL
        select *, (
            select coalesce(json_group_array("ability"), json_array())
            from (
                select 'view' as "ability" where (1 = 1)
            ) as "available_abilities"
        ) as "abilities"
        from "course_sections"
    SQL, onlyAbilities: ['view']);
});

// -- multiple abilities (UNION ALL) -------------------------------------------

it('UNION ALLs one wrapped branch per ability', function () {
    bindWarrantRules('if is_teacher they can view, update');

    assertWarrantAbilitiesSql(<<<SQL
        select *, (
            select coalesce(json_group_array("ability"), json_array())
            from (
                select * from (
                    select 'view' as "ability"
                    where (
                        course_sections.id = 'teacher:teacher-role'
                    )
                )
                union all
                select * from (
                    select 'update' as "ability"
                    where (
                        course_sections.id = 'teacher:teacher-role'
                    )
                )
            ) as "available_abilities"
        ) as "abilities"
        from "course_sections"
    SQL, onlyAbilities: ['view', 'update']);
});

// -- default (all declared) abilities -----------------------------------------

it('defaults to the declared abilities in declaration order', function () {
    bindWarrantRules('they can *');

    assertWarrantAbilitiesSql(<<<SQL
        select *, (
            select coalesce(json_group_array("ability"), json_array())
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
        ) as "abilities"
        from "course_sections"
    SQL);
});

// -- custom column key --------------------------------------------------------

it('honors a custom selected-abilities key', function () {
    bindWarrantRules('they can view');

    assertWarrantAbilitiesSql(<<<SQL
        select *, (
            select coalesce(json_group_array("ability"), json_array())
            from (
                select 'view' as "ability" where (1 = 1)
            ) as "available_abilities"
        ) as "perms"
        from "course_sections"
    SQL, onlyAbilities: ['view'], selectedAbilitiesKey: 'perms');
});

// -- empty --------------------------------------------------------------------

it('selects an empty JSON array when no abilities are requested', function () {
    assertWarrantAbilitiesSql(<<<SQL
        select *, '[]' as abilities
        from "course_sections"
    SQL, onlyAbilities: []);
});
