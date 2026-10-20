<?php

require_once __DIR__.'/Support.php';
require_once __DIR__.'/../Support/TestSupport.php';

use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\SyntaxProducerKind;

it('reads each fragment as the editor\'s, for the schema it is about', function () {
    [$workspace, $document] = openInFixture('rules.warrant', 'if is_owner they can view');
    [$analyzed] = $workspace->analyze($document);
    $producer = $analyzed->analysis->syntax->source->producer();

    expect($producer->kind)->toBe(SyntaxProducerKind::Editor)
        ->and($producer->path)->toBe($document->path)
        ->and($producer->schemaKey)->toBe($analyzed->fragment->schemaKey);
});

it('validates each fragment without binding values, which an editor never has', function () {
    [$workspace, $document] = openInFixture('rules.warrant', "for documents {\n    if is_owner(?) they can view\n    if is_owner they can edti\n}");
    [$analyzed] = $workspace->analyze($document);

    expect(array_map(static fn ($diagnostic): DiagnosticCode => $diagnostic->code, $analyzed->diagnostics))
        ->toBe([DiagnosticCode::UnknownAbility]);
});

it('reports the syntax errors of a fragment before the mistakes validating it finds', function () {
    [$workspace, $document] = openInFixture('rules.warrant', "for documents {\n    if is_owner they can edti\n    if ( they can view\n}");
    [$analyzed] = $workspace->analyze($document);
    $codes = array_map(static fn ($diagnostic): DiagnosticCode => $diagnostic->code, $analyzed->diagnostics);
    $syntaxErrors = count($analyzed->analysis->diagnostics);

    expect($syntaxErrors)->toBeGreaterThan(0)
        ->and(array_slice($codes, 0, $syntaxErrors))->toBe(array_map(static fn ($diagnostic): DiagnosticCode => $diagnostic->code, $analyzed->analysis->diagnostics))
        ->and($codes[array_key_last($codes)])->toBe(DiagnosticCode::UnknownAbility);
});
