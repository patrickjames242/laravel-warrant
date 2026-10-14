<?php

use Warrant\LanguageServer\Vocabulary\ApplicationSchemaReader;
use Warrant\Tests\Fixtures\TraitAbilities\TraitAbilitiesSchema;

/**
 * Where {@see ApplicationSchemaReader} says the ability $name of
 * {@see TraitAbilitiesSchema} is declared, as `file:line` relative to the fixtures.
 */
function abilityLocation(string $name): string
{
    $location = (new ReflectionMethod(ApplicationSchemaReader::class, 'constantLocation'))
        ->invoke(null, new ReflectionClass(TraitAbilitiesSchema::class), $name);

    return basename((string) $location['file']).':'.$location['line'];
}

it('locates an ability declared on the schema itself', function () {
    expect(abilityLocation('view'))->toBe('TraitAbilitiesSchema.php:19');
});

it('locates an ability declared in a trait the schema uses, in the trait\'s file', function () {
    expect(abilityLocation('publish'))->toBe('DeclaresPublishing.php:17');
});

it('locates an ability declared in a trait used by one of the schema\'s traits', function () {
    expect(abilityLocation('archive'))->toBe('DeclaresArchiving.php:16');
});
