<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Features\CodeActions;
use Warrant\LanguageServer\Features\Completion;
use Warrant\LanguageServer\Features\Definition;
use Warrant\LanguageServer\Features\Diagnostics;
use Warrant\LanguageServer\Features\Hover;
use Warrant\LanguageServer\Vocabulary\ApplicationSchemaReader;
use Warrant\LanguageServer\Vocabulary\ClassFileLocator;
use Warrant\LanguageServer\Vocabulary\StaticSchemaScanner;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;

/**
 * The fixture's TenancySchema, as a PHP document open in the editor, with
 * $template as the body of its tenantOnly() template.
 *
 * @return array{0: \Warrant\LanguageServer\Workspace, 1: \Warrant\LanguageServer\Documents\Document}
 */
function openTenancyWithTemplate(string $template): array
{
    $path = 'app/Schemas/TenancySchema.php';
    $text = (string) file_get_contents(fixtureRoot().'/'.$path);

    return openInFixture($path, str_replace("'if in_current_tenant they can'", var_export($template, true), $text));
}

/**
 * The codes of the diagnostics in $document.
 *
 * @return list<string>
 */
function diagnosticCodes(array $opened): array
{
    [$workspace, $document] = $opened;

    return array_column(Diagnostics::of($workspace, $document), 'code');
}

it('reads the global list from the config, in order, keeping only classes that extend GlobalSchema', function () {
    $scanner = new StaticSchemaScanner(fixtureRoot(), ClassFileLocator::forProject(fixtureRoot()));

    expect($scanner->globalSchemas())->toBe(['App\Schemas\TenancySchema', 'App\Schemas\MisfiledSchema', 'App\Schemas\AuditSchema'])
        ->and(fixtureVocabulary()->globalClasses())->toBe(['App\Schemas\TenancySchema', 'App\Schemas\AuditSchema'])
        ->and(array_keys(fixtureVocabulary()->schemas))->toBe(['documents', 'folders', 'billing'])
        ->and(fixtureVocabulary()->globalSchema('App\Schemas\TenancySchema')->key)->toBeNull()
        ->and(array_keys(fixtureVocabulary()->globalSchema('App\Schemas\TenancySchema')->conditions))
        ->toEqualCanonicalizing(['in_current_tenant', 'is_staff', 'is_tenant_staff']);
});

it('reads the globals the running application describes, and prefers them when merging', function () {
    $described = [
        'class' => 'App\Schemas\TenancySchema',
        'hasRows' => false,
        'keyArgumentCount' => 0,
        'abilities' => [],
        'conditions' => [['name' => 'in_current_tenant', 'method' => 'inCurrentTenant', 'kind' => 'Global', 'requiredArgumentCount' => 0]],
        'templates' => [],
    ];

    $fromApplication = ApplicationSchemaReader::indexFrom([], [$described]);
    $merged = VocabularyIndex::merge($fromApplication, fixtureVocabulary());

    expect($fromApplication->globalClasses())->toBe(['App\Schemas\TenancySchema'])
        ->and(array_keys($merged->globalSchema('App\Schemas\TenancySchema')->conditions))->toBe(['in_current_tenant'])
        ->and($merged->globalSchema('App\Schemas\TenancySchema')->conditions['in_current_tenant']->line)->toBe(20);
});

it('checks a name in a schema\'s rules against its own schema and then the globals', function () {
    expect(diagnosticCodes(openInFixture('rules.warrant', 'for documents { if in_current_tenant or is_owner they can view }')))->toBe([])
        ->and(diagnosticCodes(openInFixture('rules.warrant', 'for documents { if is_misfiled they can view }')))->toBe(['unknown-condition'])
        ->and(diagnosticCodes(openInFixture('rules.warrant', 'for documents { @include tenant_only for view }')))->toBe([]);
});

it('checks a name in a global\'s template against the globals alone', function () {
    expect(diagnosticCodes(openTenancyWithTemplate('if is_tenant_staff they can')))->toBe([]);

    [$workspace, $document] = openTenancyWithTemplate('if is_owner they can');
    $diagnostics = Diagnostics::of($workspace, $document);

    expect(array_column($diagnostics, 'code'))->toBe(['unknown-condition'])
        ->and($diagnostics[0]['message'])->toContain('is declared by no global schema');
});

it('holds a template\'s body to leaving its abilities off, and a global\'s to no for header', function () {
    expect(diagnosticCodes(openTenancyWithTemplate('if in_current_tenant they can view')))->toBe(['abilities-named-where-generic'])
        ->and(diagnosticCodes(openTenancyWithTemplate('for documents { if in_current_tenant they can }')))->toBe(['header-in-global-answer']);
});

it('describes and finds a condition a global declares where it is declared', function () {
    [$text, $position] = positionOfBar('for documents { if in_current_te|nant they can view }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Hover::at($workspace, $document, $position)['contents']['value'])
        ->toStartWith('**condition `in_current_tenant` on global schema `App\Schemas\TenancySchema`**');

    $location = Definition::at($workspace, $document, $position);

    expect($location['uri'])->toEndWith('TenancySchema.php')
        ->and($location['range']['start']['line'])->toBe(19);
});

it('offers the globals\' conditions after a schema\'s own, marking where each comes from', function () {
    [$text, $position] = positionOfBar('for documents { if in_| }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    $items = array_column(Completion::at($workspace, $document, $position), 'detail', 'label');

    expect($items)->toHaveKeys(['in_team', 'in_current_tenant'])
        ->and($items['in_current_tenant'])->toBe('in_current_tenant() from global App\Schemas\TenancySchema')
        ->and($items['in_team'])->not->toContain('global');
});

it('offers what may continue a condition in a derived condition\'s answer, and no rule entries', function () {
    $path = 'app/Schemas/TenancySchema.php';
    $text = (string) file_get_contents(fixtureRoot().'/'.$path);

    $labels = static function (string $answer) use ($path, $text): array {
        [$source, $position] = positionOfBar(str_replace("'is_staff and in_current_tenant'", "'{$answer}'", $text));
        [$workspace, $document] = openInFixture($path, $source);

        return array_column(Completion::at($workspace, $document, $position), 'label');
    };

    expect($labels('|'))->toBe(['in_current_tenant', 'is_staff', 'is_tenant_staff', 'can(', 'check(', 'not'])
        ->and($labels('in_|'))->toContain('in_current_tenant')
        ->and($labels('is_staff and |'))->toContain('in_current_tenant', 'not')
        ->and($labels('is_staff |'))->toBe(['and', 'or']);
});

it('suggests a global\'s condition for a misspelt name', function () {
    [$workspace, $document] = openInFixture('rules.warrant', 'for documents { if in_curent_tenant they can view }');

    $titles = array_column(CodeActions::for($workspace, $document, ['diagnostics' => Diagnostics::of($workspace, $document)]), 'title');

    expect($titles)->toBe(["Change to 'in_current_tenant'"]);
});

it('holds a keyed schema\'s template body to a for header naming its own schema', function () {
    $path = 'app/Schemas/DocumentSchema.php';
    $text = (string) file_get_contents(fixtureRoot().'/'.$path);

    $opened = static fn (string $header): array => openInFixture($path, str_replace(
        "            if is_owner they can\n",
        "            for {$header} { if is_owner they can }\n",
        $text,
    ));

    expect(diagnosticCodes($opened('documents')))->not->toContain('header-names-another-schema')
        ->and(diagnosticCodes($opened('folders')))->toContain('header-names-another-schema');
});

it('marks a condition a global declares that a global listed before it declares too', function () {
    $path = 'app/Schemas/AuditSchema.php';
    [$workspace, $document] = openInFixture($path, (string) file_get_contents(fixtureRoot().'/'.$path));
    $diagnostics = Diagnostics::of($workspace, $document);

    expect(array_column($diagnostics, 'code'))->toBe(['duplicate-global-name'])
        ->and($diagnostics[0]['range'])->toBe([
            'start' => ['line' => 14, 'character' => 4],
            'end' => ['line' => 14, 'character' => 60],
        ])
        ->and($diagnostics[0]['message'])->toStartWith('Global schemas [App\Schemas\TenancySchema] and [App\Schemas\AuditSchema] both declare condition [is_staff]');

    expect(diagnosticCodes(openTenancyWithTemplate('if in_current_tenant they can')))->not->toContain('duplicate-global-name');
});
