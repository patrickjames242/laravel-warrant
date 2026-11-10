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

it('names the constant that declares an ability', function () {
    $location = (new ReflectionMethod(ApplicationSchemaReader::class, 'constantLocation'))
        ->invoke(null, new ReflectionClass(TraitAbilitiesSchema::class), 'publish');

    expect($location['member'])->toBe('PUBLISH');
});

it('names the method that declares a condition or template, as it does the constant of an ability', function () {
    $index = ApplicationSchemaReader::indexFrom(['things' => [
        'class' => 'App\\ThingSchema',
        'hasRows' => false,
        'keyArgumentCount' => 0,
        'abilities' => [['name' => 'view', 'requiredContext' => [], 'member' => 'VIEW']],
        'conditions' => [['name' => 'is_owner', 'method' => 'isOwner', 'member' => 'isOwner', 'kind' => 'Derived', 'requiredArgumentCount' => 0]],
        'templates' => [['name' => 'owner_edits', 'method' => 'ownerEdits', 'member' => 'ownerEdits', 'requiredArgumentCount' => 0]],
    ]]);
    $schema = $index->schema('things');

    expect($schema->abilities['view']->member)->toBe('VIEW')
        ->and($schema->conditions['is_owner']->member)->toBe('isOwner')
        ->and($schema->templates['owner_edits']->member)->toBe('ownerEdits');
});

it('describes the row relations of a running schema, and reads them back', function () {
    $describe = new ReflectionMethod(ApplicationSchemaReader::class, 'describe');
    $documents = $describe->invoke(null, ArsDocSchema::class);

    expect($documents['relations'])->toHaveCount(1)
        ->and($documents['relations'][0])->toMatchArray([
            'name' => 'container',
            'method' => 'folder',
            'member' => 'folder',
            'schema' => ArsFolderSchema::class,
            'description' => 'The folder the document is in.',
        ]);

    $index = ApplicationSchemaReader::indexFrom([
        'ars_docs' => $documents,
        'ars_folders' => $describe->invoke(null, ArsFolderSchema::class),
    ]);

    expect($index->constructs()->rowRelation('container', 'ars_docs')?->targetKey)->toBe('ars_folders')
        ->and($index->schema('ars_docs')->relations['container']->member)->toBe('folder')
        ->and($index->schema('ars_folders')->relations)->toBe([]);
});

class ArsDoc extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'ars_docs';
}

class ArsFolder extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'ars_folders';
}

class ArsFolderSchema extends Warrant\Schema\Types\Classes\ModelSchema
{
    public const model = ArsFolder::class;
}

class ArsDocSchema extends Warrant\Schema\Types\Classes\ModelSchema
{
    public const model = ArsDoc::class;

    /**
     * The folder the document is in.
     */
    #[Warrant\Schema\Relations\RowRelation(ArsFolderSchema::class, key: 'container')]
    public function folder(Warrant\Schema\Relations\RowRelationContext $c): Illuminate\Contracts\Database\Query\Builder
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
    }
}
