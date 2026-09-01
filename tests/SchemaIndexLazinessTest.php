<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\Facades\Warrant;
use Warrant\Tests\Fixtures\LazilyLoadedModel;
use Warrant\Tests\Fixtures\LazilyLoadedSchema;

/*
|--------------------------------------------------------------------------
| Schema index laziness
|--------------------------------------------------------------------------
|
| The reason the registry is an index of strings rather than a pair of maps
| built from the schemas themselves: registering a schema must not load it.
| Reading a schema's `model` constant autoloads the schema, and instantiating
| the model to derive a key autoloads *and boots* the model, so the old
| eager registry paid both costs for every registered schema on the first
| check of every request.
|
| These assertions use class_exists($class, autoload: false), which reports
| whether a class is already loaded without loading it. The fixtures live in
| tests/Fixtures and are referenced by nothing else, so their loaded state is
| meaningful here.
|
*/

it('loads neither the schema nor its model when schemas are registered', function () {
    useWarrantSchemas([
        'course_sections' => WarrantTestSchema::class,
        'lazily_loaded' => LazilyLoadedSchema::class,
    ]);

    // Force the manager (and therefore the registry) to be constructed.
    Warrant::registry();

    expect(class_exists(LazilyLoadedSchema::class, false))->toBeFalse()
        ->and(class_exists(LazilyLoadedModel::class, false))->toBeFalse();
});

it('lists registered schemas without loading them', function () {
    useWarrantSchemas(['lazily_loaded' => LazilyLoadedSchema::class]);

    expect(Warrant::registry()->registeredSchemas())->toBe([LazilyLoadedSchema::class])
        ->and(class_exists(LazilyLoadedSchema::class, false))->toBeFalse();
});

it('loads a schema and its model only once that schema is resolved', function () {
    useWarrantSchemas(['lazily_loaded' => LazilyLoadedSchema::class]);

    expect(class_exists(LazilyLoadedSchema::class, false))->toBeFalse();

    expect(Warrant::registry()->resolveSchemaClassOrFail('lazily_loaded'))
        ->toBe(LazilyLoadedSchema::class);

    // Resolving cross-checks the back-reference, which loads the model too.
    expect(class_exists(LazilyLoadedSchema::class, false))->toBeTrue()
        ->and(class_exists(LazilyLoadedModel::class, false))->toBeTrue();
});
