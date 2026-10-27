<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Vocabulary\ApplicationSchemaReader;
use Warrant\PhpSource\ClassFileLocator;
use Warrant\LanguageServer\Vocabulary\StaticSchemaScanner;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;
use Warrant\Schema\Conditions\ConditionKind;

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
    $documents = fixtureVocabulary()->schema('documents');

    expect(array_keys($documents->abilities))->toBe(['view', 'edit', 'archive'])
        ->and($documents->vocabulary->ability('edit')->requiredContext)->toBe(['org'])
        ->and($documents->abilities['view']->file)->toEndWith('BaseSchema.php');
});

it('takes a constant carrying an ability attribute of the project\'s own for an ability', function () {
    expect(fixtureVocabulary()->schema('documents')->vocabulary->ability('archive'))->not->toBeNull();
});

it('reads conditions from methods, their own and their traits\', with their keys, kinds and arguments', function () {
    $documents = fixtureVocabulary()->schema('documents');

    expect(array_keys($documents->conditions))->toEqualCanonicalizing(['in_team', 'is_owner', 'in_folder', 'owner_or_team'])
        ->and($documents->vocabulary->condition('in_folder')->methodName)->toBe('insideFolder')
        ->and($documents->vocabulary->condition('in_team')->kind)->toBe(ConditionKind::Global)
        ->and($documents->vocabulary->condition('in_team')->requiredArgumentCount)->toBe(1)
        ->and($documents->conditions['in_team']->parameters)->toBe(['team', 'role'])
        ->and($documents->vocabulary->condition('owner_or_team')->requiredArgumentCount)->toBe(1)
        ->and($documents->conditions['is_owner']->description)->toBe('Whether the user wrote the document.')
        ->and($documents->conditions['is_owner']->line)->toBe(38);
});

it('reads templates, required context, and whether and how rows are named', function () {
    $vocabulary = fixtureVocabulary();

    expect(array_keys($vocabulary->schema('documents')->templates))->toBe(['owner_edits', 'shared_with'])
        ->and($vocabulary->schema('documents')->vocabulary->ruleTemplate('shared_with')->requiredArgumentCount)->toBe(1)
        ->and($vocabulary->schema('documents')->vocabulary->ruleTemplate('shared_with')->takesContext)->toBeTrue()
        ->and($vocabulary->schema('documents')->vocabulary->ruleTemplate('shared_with')->callOncePerAbility)->toBeTrue()
        ->and($vocabulary->schema('documents')->templates['shared_with']->parameters)->toBe(['team'])
        ->and($vocabulary->schema('documents')->vocabulary->ruleTemplate('owner_edits')->takesContext)->toBeFalse()
        ->and($vocabulary->schema('documents')->requiredContextKeys)->toBe(['org'])
        ->and($vocabulary->schema('documents')->contextKeys())->toBe(['org'])
        ->and($vocabulary->schema('folders')->keyArgumentCount)->toBe(2)
        ->and($vocabulary->schema('billing')->hasRows)->toBeFalse()
        ->and($vocabulary->schema('documents')->hasRows)->toBeTrue()
        ->and($vocabulary->schema('documents')->model)->toBe('App\\Models\\Document')
        ->and($vocabulary->find('documents')->rowMatcher()?->requiredArgumentCount)->toBe(1)
        ->and($vocabulary->find('billing')->rowMatcher())->toBeNull();
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
            'model' => 'App\\Models\\Document',
            'keyArgumentCount' => 1,
            'abilities' => [['name' => 'view', 'requiredContext' => []], ['name' => 'publish', 'requiredContext' => ['org']]],
            'conditions' => [['name' => 'is_owner', 'method' => 'isOwner', 'kind' => 'Row', 'requiredArgumentCount' => 0]],
            'templates' => [],
            'requiredContextKeys' => [],
        ],
    ]);

    $merged = VocabularyIndex::merge($fromApplication, $fromSource);

    expect(array_keys($merged->schema('documents')->abilities))->toBe(['view', 'publish'])
        ->and($merged->schema('documents')->conditions['is_owner']->line)->toBe(38)
        ->and($merged->schema('folders'))->not->toBeNull();
});
