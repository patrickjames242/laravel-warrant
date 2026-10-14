<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Features\CodeActions;
use Warrant\LanguageServer\Features\Completion;
use Warrant\LanguageServer\Features\Definition;
use Warrant\LanguageServer\Features\Diagnostics;
use Warrant\LanguageServer\Features\DocumentSymbols;
use Warrant\LanguageServer\Features\Hover;
use Warrant\LanguageServer\VocabularyMode;
use Warrant\LanguageServer\Workspace;

/**
 * The labels completion offers at the `|` in the `.warrant` text $source.
 *
 * @return list<string>
 */
function completionLabels(string $source): array
{
    [$text, $position] = positionOfBar($source);
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    return array_column(Completion::at($workspace, $document, $position), 'label');
}

it('places diagnostics in the PHP file the rule text is written in', function () {
    $code = "<?php\nclass X {\n    public function rules(): string\n    {\n        return <<<'WARRANT'\n            if is_owner they can edti\n            WARRANT;\n    }\n}\n";
    [$workspace, $document] = openInFixture('app/X.php', str_replace('class X', 'class DocumentSchema extends BaseSchema', "<?php\nnamespace App\\Schemas;\n".substr($code, 6)));

    $diagnostics = Diagnostics::of($workspace, $document);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]['code'])->toBe('unknown-ability')
        ->and($diagnostics[0]['range'])->toBe([
            'start' => ['line' => 6, 'character' => 33],
            'end' => ['line' => 6, 'character' => 37],
        ]);
});

it('checks syntax only when no schema could be read', function () {
    $workspace = new Workspace(fixtureRoot(), VocabularyMode::Static);
    [, $document] = openInFixture('rules.warrant', 'for nowhere { if anything they can fly } for documents { they }');
    $workspace->open($document);

    expect(array_column(Diagnostics::of($workspace, $document), 'code'))->toBe(['missing-clause']);
});

it('describes a condition, an ability and a schema on hover', function () {
    [$text, $position] = positionOfBar("# warrant-schema: documents\nif is_ow|ner they can view");
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Hover::at($workspace, $document, $position)['contents']['value'])
        ->toBe("**condition `is_owner` on `documents`**\n\n`is_owner()` · row condition\n\nWhether the user wrote the document.");

    [$text, $position] = positionOfBar('for documents { they can ed|it }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Hover::at($workspace, $document, $position)['contents']['value'])
        ->toBe("**ability `edit` on `documents`**\n\nRequires context: `org`");

    [$text, $position] = positionOfBar('for docu|ments { they can edit }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Hover::at($workspace, $document, $position)['contents']['value'])
        ->toBe("**schema `documents`** · `App\\Schemas\\DocumentSchema`\n\nRows.\n\nThe documents users write and share.");
});

it('jumps to where a condition, ability or schema is declared, and to the as that names a frame', function () {
    [$text, $position] = positionOfBar('for documents { if in_te|am(1) they can view }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    $location = Definition::at($workspace, $document, $position);
    expect($location['uri'])->toEndWith('ChecksTeams.php')
        ->and($location['range']['start']['line'])->toBe(15);

    [$text, $position] = positionOfBar('for documents { if check(in_folder(@column d|2.id) for documents(1) as d2) they can view }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Definition::at($workspace, $document, $position)['range'])->toBe([
        'start' => ['line' => 0, 'character' => 70],
        'end' => ['line' => 0, 'character' => 72],
    ]);
});

it('offers what belongs at the cursor, from the schema in scope', function () {
    expect(completionLabels('for documents { they can | }'))->toBe(['view', 'edit', 'archive', '*'])
        ->and(completionLabels('for documents { if is_| }'))->toContain('is_owner', 'in_team', 'can(', 'check(')
        ->and(completionLabels('for documents { if can(view for | }'))->toBe(['documents', 'folders', 'billing'])
        ->and(completionLabels('for documents { @include | }'))->toBe(['owner_edits', 'shared_with'])
        ->and(completionLabels('for documents { if a(@context |) they can view }'))->toBe(['org'])
        ->and(completionLabels('for documents { if check(| for folders(1, 2)) they can view }'))->toContain('is_shared')
        ->and(completionLabels('for documents { if a | }'))->toBe(['and', 'or', 'they'])
        ->and(completionLabels('|'))->toBe(['if', 'they', 'can they', '@include', 'for']);
});

it('offers nothing inside a comment or a string', function () {
    expect(completionLabels("# they can |\n"))->toBe([])
        ->and(completionLabels('for documents { they cannot view because "no |" }'))->toBe([]);
});

it('replaces the part of a name already typed', function () {
    [$text, $position] = positionOfBar('for documents { they can ed| }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Completion::at($workspace, $document, $position)[0]['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 25],
        'end' => ['line' => 0, 'character' => 27],
    ]);
});

it('replaces the @ of a reference already typed', function () {
    foreach (['for documents { @inc| }' => 16, 'for documents { @| }' => 16, 'for documents { if a(@con|) they can view }' => 21] as $source => $start) {
        [$text, $position] = positionOfBar($source);
        [$workspace, $document] = openInFixture('rules.warrant', $text);
        $items = Completion::at($workspace, $document, $position);
        $edit = $items[array_search(true, array_map(static fn (array $item): bool => str_starts_with($item['label'], '@'), $items), true)]['textEdit'];

        expect($edit['range'])->toBe([
            'start' => ['line' => 0, 'character' => $start],
            'end' => ['line' => 0, 'character' => $position['character']],
        ]);
    }
});

it('offers the closest declared names for one that is not declared', function () {
    [$workspace, $document] = openInFixture('rules.warrant', 'for documents { if is_ownr they can veiw }');
    $diagnostics = Diagnostics::of($workspace, $document);

    $titles = array_column(CodeActions::for($workspace, $document, ['diagnostics' => $diagnostics]), 'title');

    expect($titles)->toBe(["Change to 'view'", "Change to 'is_owner'"]);
});

it('reports an include naming a template that is not declared, or giving it too few arguments', function () {
    [$workspace, $document] = openInFixture('rules.warrant', "for documents {\n  can they edit { @include ownr_edits }\n  @include shared_with for view\n}");
    $diagnostics = Diagnostics::of($workspace, $document);

    expect(array_column($diagnostics, 'code'))->toBe(['unknown-template', 'too-few-arguments'])
        ->and($diagnostics[0]['range'])->toBe([
            'start' => ['line' => 1, 'character' => 27],
            'end' => ['line' => 1, 'character' => 37],
        ])
        ->and(array_column(CodeActions::for($workspace, $document, ['diagnostics' => [$diagnostics[0]]]), 'title'))
        ->toBe(["Change to 'owner_edits'"]);
});

it('outlines blocks, rules and includes as they nest', function () {
    [$workspace, $document] = openInFixture('rules.warrant', "for documents {\n  if is_owner they can edit\n  can they view { @include owner_edits }\n}");

    $symbols = DocumentSymbols::of($workspace, $document);

    expect($symbols[0]['name'])->toBe('for documents')
        ->and(array_column($symbols[0]['children'], 'name'))->toBe(['can edit', 'can they view'])
        ->and($symbols[0]['children'][1]['children'][0]['name'])->toBe('@include owner_edits');
});

it('answers at every position of half-typed and broken text without throwing', function () {
    $texts = [
        ['rules.warrant', "for documents {\n  if check(in_folder(@column d2.id) for documents(1) as d2 with org = @context o) they can view\n  can they edit { @include owner_edits }\n}"],
        ['rules.warrant', 'for documents { if check( for ) they can'],
        ['rules.warrant', 'for { if ( and or not they can , , @column . @context @include for'],
        ['rules.warrant', "é😀 for documents { if is_owner they can view } ~ \$ 'unterminated"],
        ['app/X.php', "<?php\n\$a = <<<WARRANT\n    if {\$x} a they can\n    WARRANT;\nwarrant('a or');\n\$b = <<<'WARRANT'\n"],
    ];

    foreach ($texts as [$path, $text]) {
        [$workspace, $document] = openInFixture($path, $text);
        $diagnostics = Diagnostics::of($workspace, $document);
        DocumentSymbols::of($workspace, $document);
        CodeActions::for($workspace, $document, ['diagnostics' => $diagnostics]);

        foreach (explode("\n", $text) as $line => $content) {
            for ($character = 0; $character <= strlen($content) + 1; $character++) {
                $position = ['line' => $line, 'character' => $character];
                Hover::at($workspace, $document, $position);
                Definition::at($workspace, $document, $position);
                Completion::at($workspace, $document, $position);
            }
        }
    }
})->throwsNoExceptions();
