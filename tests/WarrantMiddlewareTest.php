<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\WarrantMiddleware;

beforeEach(function () {
    useWarrantSchemas([WarrantScopedModelSchema::class]);
});

function registerWarrantTestRoute(string $uri, string $middleware): void
{
    Route::middleware([SubstituteBindings::class, $middleware])
        ->get($uri, fn () => response('ok'));
}

it('allows non-target checks by schema key', function () {
    bindWarrantRules('they can publish');

    registerWarrantTestRoute('/__warrant/non-target', 'warrant:course_sections,all,publish');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/non-target')
        ->assertOk()
        ->assertSee('ok');
});

it('defaults the match mode to all when omitted', function () {
    bindWarrantRules('they can publish');

    registerWarrantTestRoute('/__warrant/default-all', 'warrant:course_sections,publish');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/default-all')
        ->assertOk();
});

it('allows targeted checks by route parameter name', function () {
    bindWarrantRules('if is_teacher they can view');

    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    Route::bind('course_section', fn (string $value) => WarrantScopedModel::query()->find($value));
    registerWarrantTestRoute('/__warrant/target/{course_section}', 'warrant:course_section,all,view');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/target/teacher:teacher-role')
        ->assertOk();
});

it('denies requests when the user lacks the abilities', function () {
    bindWarrantRules('');

    registerWarrantTestRoute('/__warrant/forbidden', 'warrant:course_sections,all,publish');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/forbidden')
        ->assertForbidden();
});

it('rejects targeted checks when the route parameter is not a model instance', function () {
    registerWarrantTestRoute('/__warrant/invalid/{course_section}', 'warrant:course_section,all,view');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/invalid/teacher:teacher-role'))
        ->toThrow(InvalidArgumentException::class, 'must resolve to a model instance');
});

it('builds middleware strings with an implicit all match mode', function () {
    expect(WarrantMiddleware::string('course_sections', 'publish'))
        ->toBe('warrant:course_sections,publish');
    expect(WarrantMiddleware::string(WarrantScopedModelSchema::class, 'publish'))
        ->toBe('warrant:course_sections,publish');
    expect(WarrantMiddleware::string(WarrantScopedModel::class, 'publish'))
        ->toBe('warrant:course_sections,publish');
    expect(WarrantMiddleware::string('course_sections', ['view', 'update'], AbilityMatchMode::ANY))
        ->toBe('warrant:course_sections,any,view,update');
});

it('rejects unmapped model classes when building middleware strings', function () {
    expect(fn () => WarrantMiddleware::string(WarrantTestModel::class, 'publish'))
        ->toThrow(InvalidArgumentException::class, 'Unable to resolve');
});

it('builds middleware strings for standard ability helpers', function () {
    expect(WarrantMiddleware::canView('course_sections'))->toBe('warrant:course_sections,view');
    expect(WarrantMiddleware::canCreate('course_sections'))->toBe('warrant:course_sections,create');
    expect(WarrantMiddleware::canUpdate('course_sections'))->toBe('warrant:course_sections,update');
    expect(WarrantMiddleware::canDelete('course_sections'))->toBe('warrant:course_sections,delete');
    expect(WarrantMiddleware::canArchive('course_sections'))->toBe('warrant:course_sections,archive');
});

it('guards a route group with the generated middleware string', function () {
    bindWarrantRules('they can publish');

    Route::middleware(SubstituteBindings::class)->group(function () {
        WarrantMiddleware::guard('course_sections', 'publish', function () {
            Route::get('/__warrant/guard', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/guard')
        ->assertOk();
});

it('guards a route group with a standard ability helper', function () {
    bindWarrantRules('if is_teacher they can view');

    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    Route::bind('course_section', fn (string $value) => WarrantScopedModel::query()->find($value));

    Route::middleware(SubstituteBindings::class)->group(function () {
        WarrantMiddleware::canView('course_section', function () {
            Route::get('/__warrant/can-view/{course_section}', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/can-view/teacher:teacher-role')
        ->assertOk();
});

it('guard returns the middleware string when no closure is given', function () {
    expect(WarrantMiddleware::guard('course_sections', 'publish'))
        ->toBe('warrant:course_sections,publish');
});

it('builds reachability middleware strings with mode and match mode in the alias', function () {
    expect(WarrantMiddleware::couldEver('course_sections', 'view'))
        ->toBe('warrant.could-ever:course_sections,view');
    expect(WarrantMiddleware::always('course_sections', 'view'))
        ->toBe('warrant.always:course_sections,view');
    expect(WarrantMiddleware::never('course_sections', 'view'))
        ->toBe('warrant.never:course_sections,view');

    expect(WarrantMiddleware::couldEver('course_sections', ['view', 'publish'], matchMode: AbilityMatchMode::ANY))
        ->toBe('warrant.could-ever.any:course_sections,view,publish');

    // Normalizes schema/model classes to the schema key, same as ::string.
    expect(WarrantMiddleware::couldEver(WarrantScopedModelSchema::class, 'view'))
        ->toBe('warrant.could-ever:course_sections,view');
});

it('leaves an ability named like a match mode untouched after the colon', function () {
    // The whole point of the alias-prefix grammar: no reserved tokens in params.
    expect(WarrantMiddleware::couldEver('course_sections', 'any'))
        ->toBe('warrant.could-ever:course_sections,any');
});

it('passes a could-ever guard when the ability is reachable', function () {
    bindWarrantRules('if is_teacher they can view');

    registerWarrantTestRoute('/__warrant/could-ever', 'warrant.could-ever:course_sections,view');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/could-ever')
        ->assertOk();
});

it('forbids a could-ever guard when no rule grants the ability', function () {
    bindWarrantRules('');

    registerWarrantTestRoute('/__warrant/could-ever-forbidden', 'warrant.could-ever:course_sections,publish');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/could-ever-forbidden')
        ->assertForbidden();
});

it('forbids an always guard when the grant is only conditional', function () {
    bindWarrantRules('if is_teacher they can view');

    registerWarrantTestRoute('/__warrant/always', 'warrant.always:course_sections,view');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/always')
        ->assertForbidden();
});

it('passes an always guard for an unconditional grant', function () {
    bindWarrantRules('they can publish');

    registerWarrantTestRoute('/__warrant/always-ok', 'warrant.always:course_sections,publish');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/always-ok')
        ->assertOk();
});

it('passes a never guard exactly when the ability is unreachable', function () {
    bindWarrantRules('');

    registerWarrantTestRoute('/__warrant/never-ok', 'warrant.never:course_sections,publish');
    registerWarrantTestRoute('/__warrant/never-any', 'warrant.never.any:course_sections,publish');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/never-ok')
        ->assertOk();
});

it('forbids a never guard once a rule can grant the ability', function () {
    bindWarrantRules('they can publish');

    registerWarrantTestRoute('/__warrant/never-forbidden', 'warrant.never:course_sections,publish');

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/never-forbidden')
        ->assertForbidden();
});

it('guards a reachability route group with a closure', function () {
    bindWarrantRules('if is_teacher they can view');

    Route::middleware(SubstituteBindings::class)->group(function () {
        WarrantMiddleware::couldEver('course_sections', 'view', function () {
            Route::get('/__warrant/could-ever-group', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWarrantTestUser('teacher-role'))
        ->get('/__warrant/could-ever-group')
        ->assertOk();
});
