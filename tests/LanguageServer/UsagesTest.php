<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Features\Occurrences;
use Warrant\LanguageServer\Features\Usages;

/**
 * Where each of $usages starts, as `<file relative to the fixture
 * project>:<line>:<character>`.
 *
 * @param list<array{uri: string, range: array<string, mixed>}> $usages
 * @return list<string>
 */
function usageStarts(array $usages): array
{
    return array_map(
        static fn (array $usage): string => sprintf(
            '%s:%d:%d',
            substr(rawurldecode($usage['uri']), strlen('file://'.fixtureRoot()) + 1),
            $usage['range']['start']['line'],
            $usage['range']['start']['character'],
        ),
        $usages,
    );
}

it('finds every use of a condition in the project, open documents included, and none meaning another schema\'s', function () {
    [$workspace] = openInFixture('open.warrant', "for documents { if is_owner they can edit }\nfor folders { if is_owner they can view }");

    $found = Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', 'isOwner');

    expect($found['kind'])->toBe('condition')
        ->and($found['name'])->toBe('is_owner')
        ->and(usageStarts($found['usages']))->toBe([
            'app/Schemas/DocumentSchema.php:51:47',
            'app/Schemas/DocumentSchema.php:61:15',
            'app/Schemas/DocumentSchema.php:68:15',
            'resources/rules/documents.warrant:1:3',
            'open.warrant:0:19',
        ])
        ->and($found['usages'][4]['range']['end'])->toBe(['line' => 0, 'character' => 27])
        ->and($found['taken'])->toBe(['in_folder', 'in_team', 'owner_or_team']);
});

it('reads an open document as the editor has it, not as it is on disk', function () {
    $path = 'resources/rules/documents.warrant';
    [$workspace] = openInFixture($path, "# warrant-schema: documents\nif in_folder(1) they can view");

    expect(Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', 'isOwner')['usages'])
        ->not->toContain(fn (array $usage): bool => str_ends_with($usage['uri'], 'documents.warrant'));

    expect(usageStarts(Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', 'insideFolder')['usages']))
        ->toBe([$path.':1:3']);
});

it('finds a condition a trait declares by the trait\'s file, matching its method without regard to case', function () {
    [$workspace] = openInFixture('open.warrant', '');

    $found = Usages::of($workspace, fixtureRoot().'/app/Schemas/ChecksTeams.php', 'INTEAM');

    expect($found['name'])->toBe('in_team')
        ->and(usageStarts($found['usages']))->toBe([
            'app/Schemas/DocumentSchema.php:51:59',
            'app/Schemas/DocumentSchema.php:76:50',
        ]);
});

it('finds every use of an ability by the constant that declares it, and no names a rename must not take, since an ability is not renamed', function () {
    [$workspace] = openInFixture('open.warrant', 'for documents { if can(edit) they can edit }');

    $found = Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', 'EDIT');

    expect($found['kind'])->toBe('ability')
        ->and(usageStarts($found['usages']))->toBe([
            'resources/rules/documents.warrant:1:27',
            'open.warrant:0:23',
            'open.warrant:0:38',
        ])
        ->and($found['taken'])->toBe([]);
});

it('finds nothing for a member that declares nothing', function () {
    [$workspace] = openInFixture('open.warrant', '');

    expect(Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', 'rules'))->toBeNull()
        ->and(Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', 'edit'))->toBeNull();
});

it('names the declaration of the condition, template or ability at a position', function () {
    [$text, $position] = positionOfBar('for documents { if in_te|am(1) they can view }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Usages::declarationAt($workspace, $document, $position))->toBe([
        'kind' => 'condition',
        'name' => 'in_team',
        'uri' => 'file://'.fixtureRoot().'/app/Schemas/ChecksTeams.php',
        'member' => 'inTeam',
    ]);

    [$text, $position] = positionOfBar('for documents { @include owner_ed|its }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Usages::declarationAt($workspace, $document, $position)['member'])->toBe('ownerEdits');

    [$text, $position] = positionOfBar('for documents { they can ar|chive }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Usages::declarationAt($workspace, $document, $position))->toMatchArray(['kind' => 'ability', 'member' => 'ARCHIVE']);

    [$text, $position] = positionOfBar('for docu|ments { they can view }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Usages::declarationAt($workspace, $document, $position))->toBeNull();
});

it('searches a file created since the last search only once told the project\'s files have changed', function () {
    [$workspace] = openInFixture('open.warrant', '');
    $schema = fixtureRoot().'/app/Schemas/DocumentSchema.php';
    $created = fixtureRoot().'/resources/rules/created.warrant';

    expect(Usages::of($workspace, $schema, 'isOwner')['usages'])->toHaveCount(4);

    try {
        file_put_contents($created, "# warrant-schema: documents\nif is_owner they can view");

        expect(Usages::of($workspace, $schema, 'isOwner')['usages'])->toHaveCount(4);

        $workspace->forgetProjectFiles();

        expect(usageStarts(Usages::of($workspace, $schema, 'isOwner')['usages']))->toContain('resources/rules/created.warrant:1:3');
    } finally {
        @unlink($created);
    }
});

it('finds every place rule text names a schema, by its class: as a schema, and as the frame of its rows', function () {
    [$workspace] = openInFixture('open.warrant', "for folders { if is_shared they can view }\nfor documents { if can(view for folders(1)) they can view }\nfor documents { if check(in_folder(@column documents.id) for documents(1)) they can view }");

    $found = Usages::of($workspace, fixtureRoot().'/app/Schemas/FolderSchema.php', 'App\Schemas\FolderSchema');

    expect($found['kind'])->toBe('schema')
        ->and($found['name'])->toBe('folders')
        ->and($found['taken'])->toBe([])
        ->and(usageStarts($found['usages']))->toBe([
            'app/Schemas/DocumentSchema.php:69:28',
            'open.warrant:0:4',
            'open.warrant:1:32',
        ]);

    $documents = Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', '\App\Schemas\DocumentSchema');

    expect(usageStarts(array_values(array_filter($documents['usages'], static fn (array $usage): bool => str_ends_with($usage['uri'], 'open.warrant')))))
        ->toBe(['open.warrant:1:4', 'open.warrant:2:4', 'open.warrant:2:43', 'open.warrant:2:61']);
});

it('finds every place rule text names a context key, as a reference and in a with map', function () {
    [$text, $position] = positionOfBar("for documents { if can(view for folders(@context fol|der)) they can view }\nfor documents { if check(is_shared for folders(1) with folder = @context folder) they can view }");
    [$workspace, $document] = openInFixture('open.warrant', $text);

    $found = Occurrences::at($workspace, $document, $position);

    expect($found['kind'])->toBe('context')
        ->and($found['name'])->toBe('folder')
        ->and(usageStarts($found['occurrences']))->toBe([
            'app/Schemas/DocumentSchema.php:69:45',
            'open.warrant:0:49',
            'open.warrant:1:55',
            'open.warrant:1:73',
        ]);
});

it('finds the as that names a frame and each @column that refers to it, and no other frame by the same name', function () {
    [$text, $position] = positionOfBar('for documents { if check(in_folder(@column d.id) for documents(1) as d) and check(in_folder(@column d.id) for documents(2) as |d) they can view }');
    [$workspace, $document] = openInFixture('open.warrant', $text);

    $found = Occurrences::at($workspace, $document, $position);

    expect($found['kind'])->toBe('frame')
        ->and(usageStarts($found['occurrences']))->toBe(['open.warrant:0:100', 'open.warrant:0:126']);
});

it('finds no occurrences for a frame named by its schema, or a name that is no context key or frame', function () {
    [$text, $position] = positionOfBar('for documents { if check(in_folder(@column docu|ments.id) for documents(1)) they can view }');
    [$workspace, $document] = openInFixture('open.warrant', $text);

    expect(Occurrences::at($workspace, $document, $position))->toBeNull();

    [$text, $position] = positionOfBar('for documents { if is_ow|ner they can view }');
    [$workspace, $document] = openInFixture('open.warrant', $text);

    expect(Occurrences::at($workspace, $document, $position))->toBeNull();
});
