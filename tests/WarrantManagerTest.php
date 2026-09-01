<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\Facades\Warrant;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Registry\SchemaRegistry;

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

it('resolves the schema for a model class', function () {
    expect(Warrant::registry()->resolveSchemaClassOrFail(WarrantTestModel::class))->toBe(WarrantTestSchema::class);
});

it('resolves the schema for a schema key', function () {
    expect(Warrant::registry()->resolveSchemaClassOrFail('course_sections'))->toBe(WarrantTestSchema::class);
});

it('throws when no schema is registered for a model class', function () {
    expect(fn () => Warrant::registry()->resolveSchemaClassOrFail('App\\Models\\Nope'))
        ->toThrow(OutOfBoundsException::class, 'No Warrant schema registered for reference');
});

it('throws when no schema is registered for a schema key', function () {
    expect(fn () => Warrant::registry()->resolveSchemaClassOrFail('widgets'))
        ->toThrow(OutOfBoundsException::class, 'No Warrant schema registered for reference');
});

it('lists the registered schemas', function () {
    expect(Warrant::registry()->registeredSchemas())->toBe([WarrantTestSchema::class]);
});

it('throws when one schema is registered under two schema keys', function () {
    expect(fn () => new SchemaRegistry([
        'course_sections' => WarrantTestSchema::class,
        'sections' => WarrantTestSchema::class,
    ]))->toThrow(InvalidArgumentException::class, 'registered under more than one schema key');
});

it('loads no schema or model class when the registry is built', function () {
    // The point of the index: registering a schema must not autoload it, since
    // loading a schema also loads and boots its Eloquent model.
    $registry = new SchemaRegistry(['unloaded' => 'Warrant\\Tests\\Fixtures\\NeverLoadedSchema']);

    expect(class_exists('Warrant\\Tests\\Fixtures\\NeverLoadedSchema', false))->toBeFalse()
        ->and($registry->registeredSchemas())->toBe(['Warrant\\Tests\\Fixtures\\NeverLoadedSchema']);
});

it('throws when a registered class is not a schema, on first resolution only', function () {
    $registry = new SchemaRegistry(['not_a_schema' => WarrantTestModel::class]);

    expect(fn () => $registry->resolveSchemaClassOrFail('not_a_schema'))
        ->toThrow(LogicException::class, 'is not a Warrant\\Schema\\WarrantSchema');
});

it('throws when a schema names a model that does not name it back', function () {
    $registry = new SchemaRegistry(['mismatched' => MismatchedBackReferenceSchema::class]);

    expect(fn () => $registry->resolveSchemaClassOrFail('mismatched'))
        ->toThrow(LogicException::class, 'must name each other');
});

it('reports a non-static warrantSchema() instead of fatalling on the static call', function () {
    $registry = new SchemaRegistry(['course_sections' => WarrantTestSchema::class]);

    expect(fn () => $registry->resolveSchemaClassOrFail(NonStaticWarrantSchemaModel::class))
        ->toThrow(LogicException::class, 'must declare warrantSchema() as `public static`');
});

it('checks the pair from the model end when handed a model', function () {
    // WarrantSubclassedModel inherits warrantSchema() from WarrantTestModel, naming
    // WarrantTestSchema — which names the parent. Read from the schema end the pair
    // is consistent, so only the model direction catches it.
    $registry = new SchemaRegistry(['course_sections' => WarrantTestSchema::class]);

    expect($registry->resolveSchemaClassOrFail(WarrantTestSchema::class))->toBe(WarrantTestSchema::class);

    expect(fn () => $registry->resolveSchemaClassOrFail(WarrantSubclassedModel::class))
        ->toThrow(LogicException::class, 'but that schema names model [WarrantTestModel]');
});

it('throws when a schema names a model that does not use the trait', function () {
    $registry = new SchemaRegistry(['traitless' => TraitlessModelSchema::class]);

    expect(fn () => $registry->resolveSchemaClassOrFail('traitless'))
        ->toThrow(LogicException::class, 'does not use the Warrant\\HasWarrantSchema trait');
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
