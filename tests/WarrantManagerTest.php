<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\Facades\Warrant;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\WarrantManager;

beforeEach(function () {
    useWarrantSchemas([WarrantTestSchema::class]);
});

it('resolves the schema for a model class', function () {
    expect(Warrant::getSchemaForModelClass(WarrantTestModel::class))->toBe(WarrantTestSchema::class);
});

it('resolves the schema for a schema key', function () {
    expect(Warrant::getSchemaForKey('course_sections'))->toBe(WarrantTestSchema::class);
});

it('throws when no schema is registered for a model class', function () {
    expect(fn () => Warrant::getSchemaForModelClass('App\\Models\\Nope'))
        ->toThrow(OutOfBoundsException::class, 'No Warrant schema registered for model');
});

it('throws when no schema is registered for a schema key', function () {
    expect(fn () => Warrant::getSchemaForKey('widgets'))
        ->toThrow(OutOfBoundsException::class, 'No Warrant schema registered for schema key');
});

it('lists the registered schemas', function () {
    expect(Warrant::registeredSchemas())->toBe([WarrantTestSchema::class]);
});

it('throws when two schemas claim the same schema key', function () {
    expect(fn () => new WarrantManager([WarrantTestSchema::class, WarrantScopedModelSchema::class]))
        ->toThrow(InvalidArgumentException::class, 'Duplicate schema for schema key');
});

it('validates a batch of rule sets, each against its own registered schema', function () {
    // Passes silently: every name is declared by the course_sections schema.
    WarrantRuleSet::validateAll(
        WarrantRuleSet::fromSyntax('if is_teacher they can view, update', 'course_sections'),
        [WarrantRuleSet::fromSyntax('if is_advisor they can publish', 'course_sections')],
    );

    expect(true)->toBeTrue();
});

it('throws on the first unknown name across a validateAll batch', function () {
    expect(fn () => WarrantRuleSet::validateAll(
        WarrantRuleSet::fromSyntax('if is_teacher they can view', 'course_sections'),
        WarrantRuleSet::fromSyntax('they can fly', 'course_sections'),
    ))->toThrow(InvalidArgumentException::class, 'Ability [fly]');
});

it('builds a combined no-target abilities bag keyed by schema key', function () {
    bindWarrantRules('they can publish, view');

    expect(Warrant::getNoTargetAbilitiesBag(makeWarrantTestUser('teacher-role'), WarrantTestSchema::class))
        ->toBe([
            'course_sections' => [
                'schema_key' => 'course_sections',
                'abilities' => ['publish', 'view'],
                'target' => null,
            ],
        ]);
});
