<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Warden\AbilityMatchMode;
use Warden\WardenMiddleware;

beforeEach(function () {
    useWardenSchemas([WardenScopedModelSchema::class]);
});

function registerWardenTestRoute(string $uri, string $middleware): void
{
    Route::middleware([SubstituteBindings::class, $middleware])
        ->get($uri, fn () => response('ok'));
}

it('allows non-target checks by schema key', function () {
    bindWardenRules('they can publish');

    registerWardenTestRoute('/__warden/non-target', 'warden:course_sections,all,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/non-target')
        ->assertOk()
        ->assertSee('ok');
});

it('defaults the match mode to all when omitted', function () {
    bindWardenRules('they can publish');

    registerWardenTestRoute('/__warden/default-all', 'warden:course_sections,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/default-all')
        ->assertOk();
});

it('allows targeted checks by route parameter name', function () {
    bindWardenRules('if is_teacher they can view');

    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    Route::bind('course_section', fn (string $value) => WardenScopedModel::query()->find($value));
    registerWardenTestRoute('/__warden/target/{course_section}', 'warden:course_section,all,view');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/target/teacher:teacher-role')
        ->assertOk();
});

it('denies requests when the user lacks the abilities', function () {
    bindWardenRules('');

    registerWardenTestRoute('/__warden/forbidden', 'warden:course_sections,all,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/forbidden')
        ->assertForbidden();
});

it('rejects targeted checks when the route parameter is not a model instance', function () {
    registerWardenTestRoute('/__warden/invalid/{course_section}', 'warden:course_section,all,view');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/invalid/teacher:teacher-role'))
        ->toThrow(InvalidArgumentException::class, 'must resolve to a model instance');
});

it('builds middleware strings with an implicit all match mode', function () {
    expect(WardenMiddleware::string('course_sections', 'publish'))
        ->toBe('warden:course_sections,publish');
    expect(WardenMiddleware::string(WardenScopedModelSchema::class, 'publish'))
        ->toBe('warden:course_sections,publish');
    expect(WardenMiddleware::string(WardenScopedModel::class, 'publish'))
        ->toBe('warden:course_sections,publish');
    expect(WardenMiddleware::string('course_sections', ['view', 'update'], AbilityMatchMode::ANY))
        ->toBe('warden:course_sections,any,view,update');
});

it('rejects unmapped model classes when building middleware strings', function () {
    expect(fn () => WardenMiddleware::string(WardenTestModel::class, 'publish'))
        ->toThrow(InvalidArgumentException::class, 'Unable to resolve');
});

it('builds middleware strings for standard ability helpers', function () {
    expect(WardenMiddleware::canView('course_sections'))->toBe('warden:course_sections,view');
    expect(WardenMiddleware::canCreate('course_sections'))->toBe('warden:course_sections,create');
    expect(WardenMiddleware::canUpdate('course_sections'))->toBe('warden:course_sections,update');
    expect(WardenMiddleware::canDelete('course_sections'))->toBe('warden:course_sections,delete');
    expect(WardenMiddleware::canArchive('course_sections'))->toBe('warden:course_sections,archive');
});

it('guards a route group with the generated middleware string', function () {
    bindWardenRules('they can publish');

    Route::middleware(SubstituteBindings::class)->group(function () {
        WardenMiddleware::guard('course_sections', 'publish', function () {
            Route::get('/__warden/guard', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/guard')
        ->assertOk();
});

it('guards a route group with a standard ability helper', function () {
    bindWardenRules('if is_teacher they can view');

    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    Route::bind('course_section', fn (string $value) => WardenScopedModel::query()->find($value));

    Route::middleware(SubstituteBindings::class)->group(function () {
        WardenMiddleware::canView('course_section', function () {
            Route::get('/__warden/can-view/{course_section}', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/can-view/teacher:teacher-role')
        ->assertOk();
});

it('guard returns the middleware string when no closure is given', function () {
    expect(WardenMiddleware::guard('course_sections', 'publish'))
        ->toBe('warden:course_sections,publish');
});

it('builds reachability middleware strings with mode and match mode in the alias', function () {
    expect(WardenMiddleware::couldEver('course_sections', 'view'))
        ->toBe('warden.could-ever:course_sections,view');
    expect(WardenMiddleware::always('course_sections', 'view'))
        ->toBe('warden.always:course_sections,view');
    expect(WardenMiddleware::never('course_sections', 'view'))
        ->toBe('warden.never:course_sections,view');

    expect(WardenMiddleware::couldEver('course_sections', ['view', 'publish'], matchMode: AbilityMatchMode::ANY))
        ->toBe('warden.could-ever.any:course_sections,view,publish');

    // Normalizes schema/model classes to the schema key, same as ::string.
    expect(WardenMiddleware::couldEver(WardenScopedModelSchema::class, 'view'))
        ->toBe('warden.could-ever:course_sections,view');
});

it('leaves an ability named like a match mode untouched after the colon', function () {
    // The whole point of the alias-prefix grammar: no reserved tokens in params.
    expect(WardenMiddleware::couldEver('course_sections', 'any'))
        ->toBe('warden.could-ever:course_sections,any');
});

it('passes a could-ever guard when the ability is reachable', function () {
    bindWardenRules('if is_teacher they can view');

    registerWardenTestRoute('/__warden/could-ever', 'warden.could-ever:course_sections,view');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/could-ever')
        ->assertOk();
});

it('forbids a could-ever guard when no rule grants the ability', function () {
    bindWardenRules('');

    registerWardenTestRoute('/__warden/could-ever-forbidden', 'warden.could-ever:course_sections,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/could-ever-forbidden')
        ->assertForbidden();
});

it('forbids an always guard when the grant is only conditional', function () {
    bindWardenRules('if is_teacher they can view');

    registerWardenTestRoute('/__warden/always', 'warden.always:course_sections,view');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/always')
        ->assertForbidden();
});

it('passes an always guard for an unconditional grant', function () {
    bindWardenRules('they can publish');

    registerWardenTestRoute('/__warden/always-ok', 'warden.always:course_sections,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/always-ok')
        ->assertOk();
});

it('passes a never guard exactly when the ability is unreachable', function () {
    bindWardenRules('');

    registerWardenTestRoute('/__warden/never-ok', 'warden.never:course_sections,publish');
    registerWardenTestRoute('/__warden/never-any', 'warden.never.any:course_sections,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/never-ok')
        ->assertOk();
});

it('forbids a never guard once a rule can grant the ability', function () {
    bindWardenRules('they can publish');

    registerWardenTestRoute('/__warden/never-forbidden', 'warden.never:course_sections,publish');

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/never-forbidden')
        ->assertForbidden();
});

it('guards a reachability route group with a closure', function () {
    bindWardenRules('if is_teacher they can view');

    Route::middleware(SubstituteBindings::class)->group(function () {
        WardenMiddleware::couldEver('course_sections', 'view', function () {
            Route::get('/__warden/could-ever-group', fn () => response('ok'));
        });
    });

    $this->actingAs(makeWardenTestUser('teacher-role'))
        ->get('/__warden/could-ever-group')
        ->assertOk();
});
