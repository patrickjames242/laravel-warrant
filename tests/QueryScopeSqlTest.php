<?php

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| SQL surface tests — Stage 3: model query scopes
|------------------------------------------------------------------------------
|
| The HasWarrantSchema trait exposes two Eloquent scopes that delegate straight
| to the schema's query builders (see HasWarrantSchema::scopeUserHasAbility /
| scopeSelectUserAbilities):
|
|   - userHasAbility(...)     -> filterQuery(...)
|   - selectUserAbilities(...) -> selectUserAbilitiesInQuery(...)
|
| Both pass `targetSqlId: $model->getQualifiedKeyName()`, which for the fixture
| model (table `course_sections`, key `id`) is `course_sections.id` — the same
| raw target the Stage 1/2 tests pass by hand. So the SQL these scopes emit
| through Eloquent must match the schema-level SQL exactly; these tests confirm
| the trait's wiring (target derivation, base query) and nothing more.
|
| WarrantScopedModel uses WarrantScopedModelSchema (extends WarrantTestSchema),
| so the same create/publish/archive/view/update vocabulary and is_teacher /
| is_advisor conditions apply.
|
*/

// -- userHasAbility -> filterQuery --------------------------------------------

it('scopeUserHasAbility emits filterQuery SQL through Eloquent', function () {
    bindWarrantRules('if is_teacher they can view');

    $sql = WarrantScopedModel::query()
        ->userHasAbility('view', makeWarrantTestUser('teacher-role'))
        ->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "course_sections"
        where (
            course_sections.id = 'teacher:teacher-role'
        )
    SQL));
});

// -- selectUserAbilities -> selectUserAbilitiesInQuery ------------------------

it('scopeSelectUserAbilities emits the per-row JSON column through Eloquent', function () {
    bindWarrantRules('if is_teacher they can view');

    $sql = WarrantScopedModel::query()
        ->selectUserAbilities(makeWarrantTestUser('teacher-role'), 'abilities', ['view'])
        ->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
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
    SQL));
});
