<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Vocabulary\ApplicationSchemaReader;
use Warrant\LanguageServer\Vocabulary\ClassFileLocator;
use Warrant\LanguageServer\Vocabulary\StaticSchemaScanner;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;
use Warrant\Schema\ConditionKind;

it('reads the schemas config/warrant.php registers, by key, however the class is written', function () {
    $scanner = new StaticSchemaScanner(fixtureRoot(), ClassFileLocator::forProject(fixtureRoot()));

    expect($scanner->registeredSchemas())->toBe([
        'documents' => 'App\Schemas\DocumentSchema',
        'folders' => 'App\Schemas\FolderSchema',
        'billing' => 'App\Schemas\BillingSchema',
        'missing' => 'App\Schemas\MissingSchema',
    ]);
});

it('leaves out a registered schema whose class cannot be found', function () {
    expect(array_keys(fixtureVocabulary()->schemas))->toBe(['documents', 'folders', 'billing']);
});

it('reads abilities from constants, following references and inheritance, with their required context', function () {
    $documents = fixtureVocabulary()->find('documents');

    expect(array_keys($documents->abilities))->toBe(['view', 'edit', 'archive'])
        ->and($documents->getAbilityDefinition('edit')->requiredContext)->toBe(['org'])
        ->and($documents->abilities['view']->file)->toEndWith('BaseSchema.php');
});

it('takes a constant carrying an ability attribute of the project\'s own for an ability', function () {
    expect(fixtureVocabulary()->find('documents')->getAbilityDefinition('archive'))->not->toBeNull();
});

it('reads conditions from methods, their own and their traits\', with their keys, kinds and arguments', function () {
    $documents = fixtureVocabulary()->find('documents');

    expect(array_keys($documents->conditions))->toEqualCanonicalizing(['in_team', 'is_owner', 'in_folder', 'owner_or_team'])
        ->and($documents->getConditionDefinition('in_folder')->methodName)->toBe('insideFolder')
        ->and($documents->getConditionDefinition('in_team')->kind)->toBe(ConditionKind::Global)
        ->and($documents->getConditionDefinition('in_team')->requiredArgumentCount)->toBe(1)
        ->and($documents->conditions['in_team']->parameters)->toBe(['team', 'role'])
        ->and($documents->getConditionDefinition('owner_or_team')->requiredArgumentCount)->toBe(1)
        ->and($documents->conditions['is_owner']->description)->toBe('Whether the user wrote the document.')
        ->and($documents->conditions['is_owner']->line)->toBe(35);
});

it('reads templates, required context, and whether and how rows are named', function () {
    $vocabulary = fixtureVocabulary();

    expect(array_keys($vocabulary->find('documents')->templates))->toBe(['owner_edits'])
        ->and($vocabulary->find('documents')->requiredContextKeys)->toBe(['org'])
        ->and($vocabulary->find('documents')->contextKeys())->toBe(['org'])
        ->and($vocabulary->find('folders')->keyArgumentCount)->toBe(2)
        ->and($vocabulary->find('billing')->describesRows())->toBeFalse()
        ->and($vocabulary->find('documents')->canNameARow())->toBeTrue();
});

it('finds a schema\'s key by its class', function () {
    expect(fixtureVocabulary()->keyOfClass('\App\Schemas\FolderSchema'))->toBe('folders')
        ->and(fixtureVocabulary()->keyOfClass('App\Other'))->toBeNull();
});

it('merges the application\'s reading over the reading from source, keeping locations it lacks', function () {
    $fromSource = fixtureVocabulary();
    $fromApplication = ApplicationSchemaReader::indexFrom([
        'documents' => [
            'class' => 'App\Schemas\DocumentSchema',
            'hasRows' => true,
            'hasRowKey' => true,
            'keyArgumentCount' => 1,
            'abilities' => [['name' => 'view', 'requiredContext' => []], ['name' => 'publish', 'requiredContext' => ['org']]],
            'conditions' => [['name' => 'is_owner', 'method' => 'isOwner', 'kind' => 'Row', 'requiredArgumentCount' => 0]],
            'templates' => [],
            'requiredContextKeys' => [],
        ],
    ]);

    $merged = VocabularyIndex::merge($fromApplication, $fromSource);

    expect(array_keys($merged->find('documents')->abilities))->toBe(['view', 'publish'])
        ->and($merged->find('documents')->conditions['is_owner']->line)->toBe(35)
        ->and($merged->find('folders'))->not->toBeNull();
});
