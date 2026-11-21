<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Documents\Uri;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;

it('keeps its analyses when the schemas are read again and come out the same', function () {
    [$workspace, $document] = openInFixture('rules.warrant', 'for documents { if is_owner they can edit }');
    $analyzed = $workspace->analyze($document);

    expect($workspace->useVocabulary(fixtureVocabulary()))->toBeFalse()
        ->and($workspace->analyze($document)[0])->toBe($analyzed[0]);
});

it('analyses afresh when the schemas read differ from the ones known', function () {
    [$workspace, $document] = openInFixture('rules.warrant', 'for documents { if is_owner they can edit }');
    $analyzed = $workspace->analyze($document);

    expect($workspace->useVocabulary(new VocabularyIndex))->toBeTrue()
        ->and($workspace->analyze($document)[0])->not->toBe($analyzed[0]);
});

it('analyses afresh once told the project\'s classes may have changed, though the schemas have not', function () {
    [$workspace, $document] = openInFixture('rules.warrant', 'for documents { if is_owner they can edit }');
    $analyzed = $workspace->analyze($document);

    $workspace->forgetAnalyses();

    expect($workspace->analyze($document)[0])->not->toBe($analyzed[0]);
});

it('finds no rule text in a PHP file that names nothing rule text needs', function () {
    [$workspace] = openInFixture('rules.warrant', '');
    $path = fixtureRoot().'/app/Plain.php';
    $document = new Document(Uri::fromPath($path), $path, '<?php class Plain { public function handle() { return "if is_owner they can edit"; } }', 1);

    expect($workspace->analyze($document))->toBe([]);
});
