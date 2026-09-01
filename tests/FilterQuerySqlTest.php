<?php


use Warrant\Facades\Warrant;
require_once __DIR__.'/Support/TestSupport.php';

use Warrant\AbilityMatchMode;

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});


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
|     predicate per ability, joined by `and` (ALL) or `or` (ANY).
|   - per ability: `( OR of each granting rule's condition )` then, ANDed, one
|     `not <cannot condition>` per conditional `cannot` rule.
|   - the compiler builds a CompiledWhereClauseNode before emitting, so the
|     always-true term an unconditional `can` contributes is folded away rather
|     than written out: `1 = 1` survives only when the whole predicate is true,
|     and `1 = 0` only when it is false (an unconditional `cannot`, or an
|     ability with no `can` rule at all). An ANY gate with one always-true
|     ability is therefore just `1 = 1`.
|   - that same node drops the parentheses a group does not need: a condition
|     that emitted a single where clause is spliced in bare, and a group holding
|     one operand is not wrapped. Duplicate leaves are *not* deduplicated, so an
|     ALL gate over two abilities granted by one rule emits that rule twice.
|   - every condition leaf is applied inline; a negated leaf lands as
|     `not (...)` (no EXISTS wrapping). Reaching another table is the condition
|     author's own `whereExists`, which splices in as-is.
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
    $sql = Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->filterQuery(
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

it('inlines a positive row condition as a correlated predicate', function () {
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
            'advisor' = 'teacher-role'
            or
            course_sections.id = 'teacher:teacher-role'
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
    useWarrantSchemas(['course_sections' => WarrantJoinConditionSchema::class]);
    bindWarrantRules('if via_bad_join they can view');

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantJoinConditionSchema))->filterQuery(
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
    // is excluded (fail-closed), rather than being kept as NOT EXISTS would. The
    // unconditional grant's always-true term folds away, leaving only the deny.
    assertWarrantFilterSql('view', <<<SQL
        select * from "course_sections"
        where (not (course_sections.id = 'teacher:teacher-role'))
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

    // Both abilities come from the one rule, so the same leaf is emitted twice —
    // there is no dedup pass.
    assertWarrantFilterSql(['view', 'update'], <<<SQL
        select * from "course_sections"
        where (
            course_sections.id = 'teacher:teacher-role'
            and
            course_sections.id = 'teacher:teacher-role'
        )
    SQL, AbilityMatchMode::ALL);
});

it('ORs every ability predicate under ANY match mode', function () {
    bindWarrantRules('they can view if is_teacher they can update');

    // `view` is granted unconditionally, so under ANY the whole gate is true and
    // the `update` predicate is folded away rather than ORed against a `1 = 1`.
    assertWarrantFilterSql(['view', 'update'], <<<SQL
        select * from "course_sections"
        where (1 = 1)
    SQL, AbilityMatchMode::ANY);
});

// -- empty --------------------------------------------------------------------

it('leaves the query untouched when no abilities are requested', function () {
    assertWarrantFilterSql([], <<<SQL
        select * from "course_sections"
    SQL);
});
