<?php

require_once __DIR__.'/Support.php';

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

it('finds every use of an ability by the constant that declares it', function () {
    [$workspace] = openInFixture('open.warrant', 'for documents { if can(edit) they can edit }');

    $found = Usages::of($workspace, fixtureRoot().'/app/Schemas/DocumentSchema.php', 'EDIT');

    expect($found['kind'])->toBe('ability')
        ->and(usageStarts($found['usages']))->toBe([
            'resources/rules/documents.warrant:1:27',
            'open.warrant:0:23',
            'open.warrant:0:38',
        ])
        ->and($found['taken'])->toBe(['archive', 'view']);
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
