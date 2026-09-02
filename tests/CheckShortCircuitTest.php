<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Facades\Warrant;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;
use Warrant\WarrantAuthorizationException;
require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

/*
|------------------------------------------------------------------------------
| Boolean checks answer from the compiled predicate, not from SQL
|------------------------------------------------------------------------------
|
| A gate compiles to a where-clause tree, and that tree often folds to a literal
| before any row is involved: an unconditional `cannot`, an ability no rule
| grants, an unconditional `can`, or — with no target — anything gated on a row
| condition. `compileGateWhereClause()` hands the guard that literal, so a check with a
| settled answer never reaches the database.
|
| These tests assert the query *count* alongside the answer, since the answer
| alone cannot tell the two paths apart. The one place a folded `true` is not
| the whole story is a targeted check: `exists()` was also confirming the row is
| there. A loaded model has already established that; a bare key has not, so a
| key still pays for the lookup. That asymmetry is pinned below.
|
*/

function seedShortCircuitSections(): void
{
    Schema::create('course_sections', function ($table) {
        $table->string('id');
    });

    DB::table('course_sections')->insert([
        ['id' => 'teacher:teacher-role'],
        ['id' => 'other-section'],
    ]);
}

/**
 * Run $check with the query log on, returning its result and the number of
 * queries it took to get there.
 *
 * @return array{mixed, int}
 */
function measureQueries(Closure $check): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $result = $check();

    $connection->disableQueryLog();

    return [$result, count($connection->getQueryLog())];
}

function shortCircuitGuard(string $role = 'teacher-role'): object
{
    return Warrant::guard(makeWarrantTestUser($role))->forSchema(new WarrantTestSchema);
}

/**
 * The seeded row as a loaded model — fetched before any measurement starts, so
 * the fetch itself is never counted against the check.
 */
function loadedSection(string $id = 'teacher:teacher-role'): WarrantTestModel
{
    return WarrantTestModel::query()->findOrFail($id);
}

// -- targeted checks: a folded false never needs a row ------------------------

it('denies an ability no rule grants without a query', function () {
    seedShortCircuitSections();
    bindWarrantRules('they can update');
    $section = loadedSection();

    expect(measureQueries(fn () => shortCircuitGuard()->can('view', $section)))->toBe([false, 0]);
});

it('denies an unconditional cannot without a query', function () {
    seedShortCircuitSections();
    bindWarrantRules("they can view\nthey cannot view");
    $section = loadedSection();

    expect(measureQueries(fn () => shortCircuitGuard()->can('view', $section)))->toBe([false, 0]);
});

it('denies a bare key without a query too, since no row could pass', function () {
    seedShortCircuitSections();
    bindWarrantRules('they can update');

    expect(measureQueries(fn () => shortCircuitGuard()->can('view', 'teacher:teacher-role')))->toBe([false, 0]);
});

it('reports cannot() from the folded predicate as well', function () {
    seedShortCircuitSections();
    bindWarrantRules('they can update');
    $section = loadedSection();

    expect(measureQueries(fn () => shortCircuitGuard()->cannot('view', $section)))->toBe([true, 0]);
});

// -- targeted checks: a folded true, and the row-existence caveat -------------

it('grants an unconditional ability on a loaded model without a query', function () {
    seedShortCircuitSections();
    bindWarrantRules('they can view');
    $section = loadedSection();

    expect(measureQueries(fn () => shortCircuitGuard()->can('view', $section)))->toBe([true, 0]);
});

it('still looks up a bare key, which has not been shown to exist', function () {
    seedShortCircuitSections();
    bindWarrantRules('they can view');

    expect(measureQueries(fn () => shortCircuitGuard()->can('view', 'teacher:teacher-role')))->toBe([true, 1]);
});

it('keeps denying a key that is not in the table', function () {
    seedShortCircuitSections();
    bindWarrantRules('they can view');

    expect(measureQueries(fn () => shortCircuitGuard()->can('view', 'no-such-section')))->toBe([false, 1]);
});

// -- targeted checks: a real predicate still runs -----------------------------

it('queries a row condition, and answers per row', function () {
    seedShortCircuitSections();
    bindWarrantRules('if is_teacher they can view');
    $teacherSection = loadedSection();
    $otherSection = loadedSection('other-section');

    expect(measureQueries(fn () => shortCircuitGuard()->can('view', $teacherSection)))->toBe([true, 1]);
    expect(measureQueries(fn () => shortCircuitGuard()->can('view', $otherSection)))->toBe([false, 1]);
});

// -- a constant crossing the ability boundary ---------------------------------

it('folds an ANY gate as soon as one ability is unconditional', function () {
    seedShortCircuitSections();
    bindWarrantRules("they can view\nif is_teacher they can update");
    $section = loadedSection('other-section');

    // `update`'s row condition never has to be evaluated — nor does the row.
    expect(measureQueries(fn () => shortCircuitGuard()->canAny(['view', 'update'], $section)))->toBe([true, 0]);
});

it('folds an ALL gate as soon as one ability is denied', function () {
    seedShortCircuitSections();
    bindWarrantRules("they cannot view\nif is_teacher they can update");
    $section = loadedSection();

    expect(measureQueries(fn () => shortCircuitGuard()->can(['view', 'update'], $section)))->toBe([false, 0]);
});

// -- no-target checks ---------------------------------------------------------

it('grants an unconditional no-target ability without a query', function () {
    bindWarrantRules('they can view');

    expect(measureQueries(fn () => shortCircuitGuard()->can('view')))->toBe([true, 0]);
});

it('denies an ungranted no-target ability without a query', function () {
    bindWarrantRules('they can update');

    expect(measureQueries(fn () => shortCircuitGuard()->can('view')))->toBe([false, 0]);
});

it('denies a no-target ability gated on a row condition without a query', function () {
    bindWarrantRules('if is_teacher they can view');

    // No row to test, so the condition is forced false — nothing left to ask.
    expect(measureQueries(fn () => shortCircuitGuard()->can('view')))->toBe([false, 0]);
});

it('queries a no-target ability gated on a global condition', function () {
    bindWarrantRules('if is_advisor they can view');

    expect(measureQueries(fn () => shortCircuitGuard('advisor')->can('view')))->toBe([true, 1]);
    expect(measureQueries(fn () => shortCircuitGuard()->can('view')))->toBe([false, 1]);
});

// -- the throwing siblings keep their messages -------------------------------

it('authorize() still diagnoses a folded denial', function () {
    seedShortCircuitSections();
    bindWarrantRuleSet(new WarrantRuleSet(WarrantTestSchema::class, [
        WarrantRule::build()->theyCannotBecause('view', 'not yours')->toRule(),
    ]));
    $section = loadedSection();

    // The check itself folds to false with no query; the diagnosis that follows
    // still reads the rules and surfaces the cannot's own message.
    expect(fn () => shortCircuitGuard()->authorize('view', $section))
        ->toThrow(WarrantAuthorizationException::class, 'not yours');
});
