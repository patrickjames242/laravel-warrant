<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\AbilityMatchMode;

/*
|------------------------------------------------------------------------------
| SQL surface tests — Stage 1: filterQuery()
|------------------------------------------------------------------------------
|
| These lock in the *exact SQL* filterQuery() emits, not just its row results.
| Each test binds a rule set, builds the query, and compares the emitted SQL
| (with bindings already substituted, via toRawSql()) against a readable,
| hand-written expectation. Both sides are run through normalizeWarrantSql(),
| so formatting (whitespace, newlines) and the query builder's redundant
| *doubled* parentheses — `((E))` → `(E)` — are irrelevant; only the tokens,
| literals, operators, and genuine parenthesised structure must match.
|
| How the SQL is shaped (from RuleSetCompiler + BuildsAccessQueries):
|   - filterQuery wraps everything in one outer `where (...)`; inside it, one
|     nested predicate per ability, joined by `and` (ALL) or `or` (ANY).
|   - per ability: `( OR of each granting rule's condition )` then, ANDed, one
|     `(not <cannot condition>)` group per conditional `cannot` rule.
|   - an unconditional `can` contributes `1 = 1`; an unconditional `cannot`, or
|     an ability with no `can` rule at all, collapses the predicate to `1 = 0`.
|   - every condition leaf is applied inline as a nested where-group; a negated
|     leaf lands as `not (...)` (no EXISTS wrapping). Reaching another table is
|     the condition author's own `whereExists`, which splices in as-is.
|   - the target id is injected raw by the fixture conditions (isTeacher writes
|     `course_sections.id = ?`), while the base table is grammar-quoted.
|
| Fixture conditions (tests/Support/TestSupport.php, WarrantTestSchema), shown
| here with the fixture user's role ("teacher-role") already substituted:
|   isTeacher (targeted): whereRaw("<targetSqlId> = ?", ["teacher:teacher-role"])
|   isAdvisor (global):   whereRaw('? = ?', ['advisor', 'teacher-role'])
|
*/

/**
 * Build filterQuery for the fixture schema (user role "teacher-role", target
 * `course_sections.id`) and assert its normalized, bindings-substituted SQL.
 *
 * @param  string|array<int, string>  $abilities
 */
function assertWarrantFilterSql(
    string|array $abilities,
    string $expectedSql,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): void {
    $sql = (new WarrantTestSchema)->filterQuery(
        makeWarrantTestUser('teacher-role'),
        warrantTestQuery(),
        'course_sections.id',
        $abilities,
        $matchMode,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql($expectedSql));
}

// -- grant shapes -------------------------------------------------------------

it('emits an always-true predicate for an unconditional grant', function () {
    bindWarrantRules('they can view');

    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (1 = 1)
    SQL);
});

it('emits an always-true predicate for a wildcard grant', function () {
    bindWarrantRules('they can *');

    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (1 = 1)
    SQL);
});

it('emits 1 = 0 when no rule grants the ability', function () {
    bindWarrantRules('if is_teacher they can update');

    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (1 = 0)
    SQL);
});

// -- condition leaves ---------------------------------------------------------

it('inlines a positive targeted condition as a correlated predicate', function () {
    bindWarrantRules('if is_teacher they can view');

    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (
            course_sections.id = 'teacher:teacher-role'
        )
    SQL);
});

it('ORs two inlined condition leaves for an or-expression', function () {
    bindWarrantRules('if is_advisor or is_teacher they can view');

    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (
            ('advisor' = 'teacher-role')
            or
            (course_sections.id = 'teacher:teacher-role')
        )
    SQL);
});

it('inlines a relational condition written as a correlated whereExists', function () {
    bindWarrantRules('if via_join they can view');

    // via_join reaches another table via whereExists (the required idiom now that
    // top-level joins are banned). The author's EXISTS is spliced in inline — no
    // compiler-injected wrapper or `warrant_exists` scaffold.
    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (
            exists (
                select * from "enrollments"
                where "enrollments"."section_id" = "course_sections"."id"
                    and enrollments.user_id = 'teacher-role'
            )
        )
    SQL);
});

it('throws when a condition emits a top-level join instead of a where clause', function () {
    bindWarrantRules('if via_bad_join they can view');

    expect(fn () => (new WarrantJoinConditionSchema)->filterQuery(
        makeWarrantTestUser('teacher-role'),
        warrantTestQuery(),
        'course_sections.id',
        'view',
    )->toRawSql())
        ->toThrow(
            InvalidArgumentException::class,
            'may only add where clauses',
        );
});

// -- deny-overrides -----------------------------------------------------------

it('ANDs an inline negated leaf for a conditional cannot', function () {
    bindWarrantRules('they can view if is_teacher they cannot view');

    // The cannot compiles to `AND NOT(condition)` inline — no NOT EXISTS wrapper.
    // A NULL target column therefore follows SQL's three-valued logic and the row
    // is excluded (fail-closed), rather than being kept as NOT EXISTS would.
    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (
            (1 = 1)
            and
            (not (course_sections.id = 'teacher:teacher-role'))
        )
    SQL);
});

it('emits 1 = 0 for an unconditional cannot regardless of grants', function () {
    bindWarrantRules('they can view they cannot view');

    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (1 = 0)
    SQL);
});

// -- match modes --------------------------------------------------------------

it('ANDs every ability predicate under ALL match mode', function () {
    bindWarrantRules('if is_teacher they can view, update');

    assertWarrantFilterSql(['view', 'update'], <<<SQL
        select * from "course_sections"
        where (
            (course_sections.id = 'teacher:teacher-role')
            and
            (course_sections.id = 'teacher:teacher-role')
        )
    SQL, AbilityMatchMode::ALL);
});

it('ORs every ability predicate under ANY match mode', function () {
    bindWarrantRules('they can view if is_teacher they can update');

    assertWarrantFilterSql(['view', 'update'], <<<SQL
        select * from "course_sections"
        where (
            (1 = 1)
            or
            (course_sections.id = 'teacher:teacher-role')
        )
    SQL, AbilityMatchMode::ANY);
});

// -- empty --------------------------------------------------------------------

it('leaves the query untouched when no abilities are requested', function () {
    assertWarrantFilterSql([], <<<SQL
        select * from "course_sections"
    SQL);
});
