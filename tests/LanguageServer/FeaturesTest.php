<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Features\CodeActions;
use Warrant\LanguageServer\Features\Completion;
use Warrant\LanguageServer\Features\Definition;
use Warrant\LanguageServer\Features\Diagnostics;
use Warrant\LanguageServer\Features\DocumentSymbols;
use Warrant\LanguageServer\Features\Hover;
use Warrant\LanguageServer\Features\InlayHints;
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

    expect(array_column(Diagnostics::of($workspace, $document), 'code'))->toBe(['missing-can-or-cannot-clause']);
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
        ->toBe("**schema `documents`** · `App\\Schemas\\DocumentSchema`\n\nRows from `App\\Models\\Document`.\n\nThe documents users write and share.");
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
        ->and(completionLabels('for documents { if can(view for | }'))->toBe(['any of', 'all of', 'its', 'documents', 'folders', 'billing'])
        ->and(completionLabels('for documents { if can(| for folders(1)) they can view }'))->toBe(['view', 'share'])
        ->and(completionLabels('for documents { if can(sh| for folders(1)) they can view }'))->toBe(['view', 'share'])
        ->and(completionLabels('for documents { if can(|) they can view }'))->toBe(['view', 'edit', 'archive'])
        ->and(completionLabels('for documents { if can | they can view }'))->toBe(['view', 'edit', 'archive'])
        ->and(completionLabels('for documents { if is_owner and not can | they can view }'))->toBe(['view', 'edit', 'archive'])
        ->and(completionLabels('for documents { @include | }'))->toBe(['owner_edits', 'shared_with', 'tenant_only'])
        ->and(completionLabels('for documents { if a(@context |) they can view }'))->toBe(['org'])
        ->and(completionLabels('for documents { if check(| for folders(1, 2)) they can view }'))->toContain('is_shared')
        ->and(completionLabels('for documents { if a | }'))->toBe(['and', 'or', 'they'])
        ->and(completionLabels('|'))->toBe(['if', 'they', 'can they', 'when', '@include', 'for']);
});

it('offers no check( inside a check(...) predicate, at any depth', function () {
    expect(completionLabels('for documents { if check(| for folders(1, 2)) they can view }'))->toContain('can(', 'not')->not->toContain('check(')
        ->and(completionLabels('for documents { if check(is_shared and (| or is_shared) for folders(1, 2)) they can view }'))->not->toContain('check(')
        ->and(completionLabels('for documents { if (| or is_owner) they can view }'))->toContain('check(');
});

it('offers a condition after when, and otherwise after the body it closes', function () {
    expect(completionLabels('for documents { when | }'))->toContain('is_owner', 'in_team', 'can(', 'check(', 'not')
        ->and(completionLabels('for documents { when can | }'))->toBe(['view', 'edit', 'archive'])
        ->and(completionLabels('for documents { when is_owner | }'))->toBe(['and', 'or'])
        ->and(completionLabels('for documents { when is_owner { | } }'))->toBe(['if', 'they', 'can they', 'when', '@include'])
        ->and(completionLabels('for documents { when is_owner { they can view } | }'))->toBe(['otherwise', 'if', 'they', 'can they', 'when', '@include'])
        ->and(completionLabels('for documents { when can(view for folders(1)) { they can view } | }'))->toContain('otherwise')
        ->and(completionLabels('for documents { when a { when b { they can view } } | }'))->toContain('otherwise')
        ->and(completionLabels('for documents { when a { they can view } otherwise | }'))->toBe([])
        ->and(completionLabels('for documents { when a { they can view } otherwise { they can edit } | }'))->not->toContain('otherwise')
        ->and(completionLabels('for documents { can they view { they can } | }'))->not->toContain('otherwise')
        ->and(completionLabels('for documents { they can view } |'))->not->toContain('otherwise');
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

it('offers row relations after its, and reads a relation handle through to the schema it reaches', function () {
    expect(completionLabels('for documents { if can(view for its |) they can view }'))->toBe(['folder'])
        ->and(completionLabels('for folders { if can(view for its |) they can share }'))->toBe(['docs'])
        ->and(completionLabels('for documents { if can(| for its folder) they can view }'))->toBe(['view', 'share'])
        ->and(completionLabels('for documents { if check(| for its folder) they can view }'))->toContain('is_shared')
        ->and(completionLabels('for documents { if check(a(@column |) for its folder) they can view }'))->toContain('folder');
});

it('offers a quantifier in a handle, of after it, and then only what has rows', function () {
    expect(completionLabels('for documents { if can(view for all | }'))->toBe(['of'])
        ->and(completionLabels('for documents { if check(is_shared for any | }'))->toBe(['of'])
        ->and(completionLabels('for documents { if can(view for all of | }'))->toBe(['its', 'documents', 'folders'])
        ->and(completionLabels('for billing { if can(view for any of | }'))->toBe(['documents', 'folders'])
        ->and(completionLabels('for documents { @include owner_edits for | }'))->not->toContain('any of', 'all of');
});

it('reads a quantified handle through to the schema it reaches', function () {
    expect(completionLabels('for documents { if can(| for all of its folder) they can view }'))->toBe(['view', 'share'])
        ->and(completionLabels('for documents { if can(| for any of folders(1)) they can view }'))->toBe(['view', 'share'])
        ->and(completionLabels('for documents { if check(| for all of its folder) they can view }'))->toContain('is_shared')
        ->and(completionLabels('for documents { if check(a(@column |) for all of its folder) they can view }'))->toContain('folder');
});

it('reports a quantifier on a handle that selects no rows, over the handle', function () {
    $text = 'for documents { if can(share for all of folders) they can view }';
    [$workspace, $document] = openInFixture('rules.warrant', $text);
    $diagnostics = Diagnostics::of($workspace, $document);

    expect(array_column($diagnostics, 'code'))->toBe(['quantifier-without-row'])
        ->and($diagnostics[0]['range'])->toBe([
            'start' => ['line' => 0, 'character' => strpos($text, 'all of')],
            'end' => ['line' => 0, 'character' => strpos($text, ')')],
        ]);
});

it('offers its only in a handle of a schema that declares a relation', function () {
    expect(completionLabels('for billing { if can(view for | }'))->not->toContain('its')
        ->and(completionLabels('for documents { @include owner_edits for | }'))->not->toContain('its');
});

it('describes a row relation on hover, and jumps to the method that declares it', function () {
    [$text, $position] = positionOfBar('for documents { if can(view for its fol|der) they can view }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Hover::at($workspace, $document, $position)['contents']['value'])
        ->toBe("**row relation `folder` on `documents`**\n\nReaches rows of `folders` · `App\\Schemas\\FolderSchema`\n\nThe folder the document is filed in.")
        ->and(Definition::at($workspace, $document, $position)['uri'])->toEndWith('DocumentSchema.php');
});

it('reports a relation the schema does not declare, and offers the closest one', function () {
    [$workspace, $document] = openInFixture('rules.warrant', 'for documents { if can(view for its foldr) they can view }');
    $diagnostics = Diagnostics::of($workspace, $document);

    expect(array_column($diagnostics, 'code'))->toBe(['unknown-relation'])
        ->and(array_column(CodeActions::for($workspace, $document, ['diagnostics' => $diagnostics]), 'title'))
        ->toBe(["Change to 'folder'"]);
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

/**
 * Each hint inlay hints show in the `.warrant` text $text, as its label after
 * the text before it.
 *
 * @return list<string>
 */
function inlayHints(string $text): array
{
    [$workspace, $document] = openInFixture('rules.warrant', $text);
    $lines = explode("\n", $text);

    return array_map(
        static fn (array $hint): string => substr($lines[$hint['position']['line']], 0, $hint['position']['character']).$hint['label'],
        InlayHints::in($workspace, $document, []),
    );
}

it('names the parameter of each literal and @sql argument a condition is called with', function () {
    expect(inlayHints("for documents {\n  if in_team(1, 'admin') and in_folder(@sql \"7\") they can view\n}"))->toEqualCanonicalizing([
        '  if in_team(team:',
        "  if in_team(1, role:",
        "  if in_team(1, 'admin') and in_folder(folder:",
    ]);
});

it('names the parameters of a condition by the schema a check reads it against', function () {
    expect(inlayHints('for folders { if check(in_folder(1) for documents(1)) they can view }'))->toBe([
        'for folders { if check(in_folder(folder:',
    ]);
});

it('names the parameters of a derived condition and of a template', function () {
    expect(inlayHints("for documents {\n  if owner_or_team(4) they can view\n  @include shared_with(5) for edit\n}"))->toEqualCanonicalizing([
        '  if owner_or_team(team:',
        '  @include shared_with(team:',
    ]);
});

it('names no parameter of a binding, @context or @column argument, nor of a condition that is not declared', function () {
    expect(inlayHints('for documents { if in_team(@context org, :level) or in_folder(@column owner_id) or nowhere(1) they can view }'))->toBe([]);
});

it('places the hints in the PHP file the rule text is written in', function () {
    $code = "<?php\nnamespace App\\Schemas;\nclass DocumentSchema extends BaseSchema {\n    public function rules(): string\n    {\n        return <<<'WARRANT'\n            if in_folder(3) they can view\n            WARRANT;\n    }\n}\n";
    [$workspace, $document] = openInFixture('app/X.php', $code);

    expect(InlayHints::in($workspace, $document, []))->toBe([
        ['position' => ['line' => 6, 'character' => 25], 'label' => 'folder:', 'kind' => 2, 'paddingRight' => true],
    ]);
});

it('outlines blocks, rules and includes as they nest', function () {
    [$workspace, $document] = openInFixture('rules.warrant', "for documents {\n  if is_owner they can edit\n  can they view { @include owner_edits }\n}");

    $symbols = DocumentSymbols::of($workspace, $document);

    expect($symbols[0]['name'])->toBe('for documents')
        ->and(array_column($symbols[0]['children'], 'name'))->toBe(['can edit', 'can they view'])
        ->and($symbols[0]['children'][1]['children'][0]['name'])->toBe('@include owner_edits');
});

it('outlines a when block by its condition, holding the entries of both its bodies', function () {
    [$workspace, $document] = openInFixture('rules.warrant', "for documents {\n  when is_owner and in_team {\n    they can edit\n  } otherwise {\n    @include owner_edits\n  }\n}");

    $symbols = DocumentSymbols::of($workspace, $document);

    expect(array_column($symbols[0]['children'], 'name'))->toBe(['when is_owner and in_team'])
        ->and(array_column($symbols[0]['children'][0]['children'], 'name'))->toBe(['can edit', '@include owner_edits']);
});

it('answers at every position of half-typed and broken text without throwing', function () {
    $texts = [
        ['rules.warrant', "for documents {\n  if check(in_folder(@column d2.id) for documents(1) as d2 with org = @context o) they can view\n  can they edit { @include owner_edits }\n}"],
        ['rules.warrant', 'for documents { if check( for ) they can'],
        ['rules.warrant', "for documents {\n  when is_owner { they can view } otherwise when { otherwise { when\n  when can( { } otherwise"],
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

/**
 * The text each completion at the `|` in the `.warrant` text $source inserts,
 * by label, for an editor that does or does not take snippets, with `$0`
 * marking where a snippet leaves the cursor.
 *
 * @return array<string, string>
 */
function completionInserts(string $source, bool $snippets = true): array
{
    [$text, $position] = positionOfBar($source);
    [$workspace, $document] = openInFixture('rules.warrant', $text);
    $inserts = [];

    foreach (Completion::at($workspace, $document, $position, $snippets) as $item) {
        expect(isset($item['insertTextFormat']))->toBe(str_contains($item['textEdit']['newText'], '$0'));
        $inserts[$item['label']] = $item['textEdit']['newText'];
    }

    return $inserts;
}

it('opens the parentheses of a condition that takes arguments, with the cursor inside', function () {
    $inserts = completionInserts("# warrant-schema: documents\nif |");

    expect($inserts['in_folder'])->toBe('in_folder($0)')
        ->and($inserts['in_team'])->toBe('in_team($0)')
        ->and($inserts['is_owner'])->toBe('is_owner');
});

it('opens the parentheses of a template that takes arguments', function () {
    $inserts = completionInserts("# warrant-schema: documents\n@include |");

    expect($inserts['shared_with'])->toBe('shared_with($0)')
        ->and($inserts['owner_edits'])->toBe('owner_edits');
});

it('opens the row of a handle on a schema with rows, and never of a for header', function () {
    $handle = completionInserts("# warrant-schema: documents\nif can(edit for |) they can view");
    $header = completionInserts('for |');

    expect($handle['folders'])->toBe('folders($0)')
        ->and($handle['billing'] ?? null)->not->toBe('billing($0)')
        ->and($header['folders'])->toBe('folders');
});

it('leaves the parentheses alone when one follows the cursor, or the editor takes no snippets', function () {
    expect(completionInserts("# warrant-schema: documents\nif in_f|(1) they can view")['in_folder'])->toBe('in_folder')
        ->and(completionInserts("# warrant-schema: documents\nif |", snippets: false)['in_folder'])->toBe('in_folder');
});
