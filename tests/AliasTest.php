<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\Schema\Conditions\Alias;
use Warrant\Schema\Conditions\AliasFactory;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\TargetedConditionContext;

// -- Alias value object -------------------------------------------------------

it('exposes the join target, qualified columns, and bare name', function () {
    $alias = new Alias('course_sections', '__warrant_course_sections_0');

    expect($alias->table())->toBe('course_sections as __warrant_course_sections_0')
        ->and($alias->col('id'))->toBe('__warrant_course_sections_0.id')
        ->and($alias->col('department_id'))->toBe('__warrant_course_sections_0.department_id')
        ->and((string) $alias)->toBe('__warrant_course_sections_0')
        ->and($alias->base)->toBe('course_sections');
});

// -- Determinism --------------------------------------------------------------

it('produces the same aliases for the same sequence of calls', function () {
    $first = collect(range(1, 3))->map(fn () => new AliasFactory)
        ->map(fn (AliasFactory $f) => [
            (string) $f->next('course_sections'),
            (string) $f->next('course_sections'),
            (string) $f->next('enrollments'),
        ]);

    // Every independent run of the identical call sequence yields identical names.
    expect($first->unique()->count())->toBe(1)
        ->and($first->first())->toBe([
            '__warrant_course_sections_0',
            '__warrant_course_sections_1',
            '__warrant_enrollments_2',
        ]);
});

it('does not depend on clock, rng, or object identity', function () {
    // Two factories built at different times still agree call-for-call.
    $a = new AliasFactory;
    $b = new AliasFactory;

    expect((string) $a->next('t'))->toBe((string) $b->next('t'))
        ->and((string) $a->next('t'))->toBe((string) $b->next('t'));
});

// -- Uniqueness ---------------------------------------------------------------

it('assigns a distinct alias to every call, monotonic across all bases', function () {
    $factory = new AliasFactory;

    $names = collect([
        $factory->next('course_sections'),
        $factory->next('course_sections'),
        $factory->next('enrollments'),
        $factory->next('course_sections'),
    ])->map(fn (Alias $a) => (string) $a);

    expect($names->unique())->toHaveCount(4)
        ->and($names->all())->toBe([
            '__warrant_course_sections_0',
            '__warrant_course_sections_1',
            '__warrant_enrollments_2',
            '__warrant_course_sections_3',
        ]);
});

it('stays unique when two bases collapse to the same slug', function () {
    $factory = new AliasFactory;

    // Both truncate to the same 18-char slug; only the monotonic counter separates them.
    $one = (string) $factory->next('course_sections_archive_2024_snapshot');
    $two = (string) $factory->next('course_sections_archive_2024_backup');

    expect($one)->not->toBe($two)
        ->and($one)->toEndWith('_0')
        ->and($two)->toEndWith('_1');
});

// -- Prefix / namespace -------------------------------------------------------

it('namespaces every alias with the reserved prefix by default', function () {
    $factory = new AliasFactory;

    expect((string) $factory->next('anything'))->toStartWith(AliasFactory::DEFAULT_PREFIX);
});

it('honours a custom prefix', function () {
    $factory = new AliasFactory('__acme_wrnt_');

    expect((string) $factory->next('course_sections'))->toBe('__acme_wrnt_course_sections_0');
});

// -- Slug hygiene -------------------------------------------------------------

it('slugifies and bounds the readable portion of the base', function () {
    $factory = new AliasFactory;

    // Str::slug lowercases, drops punctuation, and joins words with underscores;
    // the readable slug is then capped at 18 chars ("publiccourse_secti").
    $alias = (string) $factory->next('Public.Course Sections! 2024');

    expect($alias)->toBe('__warrant_publiccourse_secti_0');
});

// -- Context wiring -----------------------------------------------------------

it('delegates alias() from the condition contexts to their factory', function () {
    $user = Mockery::mock(Authenticatable::class);
    $query = Mockery::mock(Illuminate\Contracts\Database\Query\Builder::class);

    $targeted = new TargetedConditionContext($user, $query, 'course_sections.id');
    $global = new GlobalConditionContext($user, $query);

    // Each context owns its own factory, so both start their own sequence at 0.
    expect($targeted->alias('enrollments'))->toBeInstanceOf(Alias::class)
        ->and((string) $targeted->alias('enrollments'))->toBe('__warrant_enrollments_1')
        ->and((string) $global->alias('enrollments'))->toBe('__warrant_enrollments_0');
});
