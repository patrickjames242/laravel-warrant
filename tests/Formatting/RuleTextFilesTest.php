<?php

use Warrant\DSL\Formatting\RuleTextFiles;
use Warrant\DSL\Formatting\UnifiedDiff;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/rule-text-files-'.bin2hex(random_bytes(4));

    foreach ([
        'rules/documents.warrant',
        'rules/folders.warrant',
        'app/Schemas/DocumentSchema.php',
        'app/Schemas/Deep/FolderSchema.php',
        'app/Notes.txt',
        'Payroll V2/rules.warrant',
        'rules[1].warrant',
        'vendor/package/Schema.php',
        'storage/cache.php',
        '.hidden/rules.warrant',
    ] as $file) {
        @mkdir(dirname($this->directory.'/'.$file), recursive: true);
        file_put_contents($this->directory.'/'.$file, '');
    }
});

afterEach(function () {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->directory);
});

/**
 * The files RuleTextFiles finds for $arguments, relative to the test directory.
 *
 * @param list<string> $arguments
 * @return list<string>
 */
function ruleTextFilesFor(string $directory, array $arguments): array
{
    return array_map(
        static fn (string $file): string => RuleTextFiles::relativeTo($file, $directory),
        RuleTextFiles::matching($arguments, $directory)->files,
    );
}

it('finds every .warrant and .php file outside vendor, storage and hidden directories, with no paths', function () {
    expect(ruleTextFilesFor($this->directory, []))->toBe([
        'Payroll V2/rules.warrant',
        'app/Schemas/Deep/FolderSchema.php',
        'app/Schemas/DocumentSchema.php',
        'rules/documents.warrant',
        'rules/folders.warrant',
        'rules[1].warrant',
    ]);
});

it('takes files, directories and paths with spaces as written', function () {
    expect(ruleTextFilesFor($this->directory, ['rules', 'Payroll V2/rules.warrant', 'rules[1].warrant', 'vendor/package/Schema.php']))->toBe([
        'Payroll V2/rules.warrant',
        'rules/documents.warrant',
        'rules/folders.warrant',
        'rules[1].warrant',
        'vendor/package/Schema.php',
    ]);
});

it('reads glob patterns, with ** for any number of directories and {a,b} for alternatives', function () {
    expect(ruleTextFilesFor($this->directory, ['app/**/*Schema.php']))->toBe(['app/Schemas/Deep/FolderSchema.php', 'app/Schemas/DocumentSchema.php'])
        ->and(ruleTextFilesFor($this->directory, ['app/*/*.php']))->toBe(['app/Schemas/DocumentSchema.php'])
        ->and(ruleTextFilesFor($this->directory, ['rules/{documents,nothing}.warrant']))->toBe(['rules/documents.warrant'])
        ->and(ruleTextFilesFor($this->directory, ['rules/?olders.warrant']))->toBe(['rules/folders.warrant']);
});

it('lists the arguments that match nothing and the files that hold no rule text', function () {
    $found = RuleTextFiles::matching(['nothing.warrant', 'rules/*.php', 'app/Notes.txt'], $this->directory);

    expect($found->files)->toBe([])
        ->and($found->unmatched)->toBe(['nothing.warrant', 'rules/*.php'])
        ->and($found->unsupported)->toBe(['app/Notes.txt']);
});

it('writes a unified diff of the lines that changed, with three lines either side', function () {
    expect(UnifiedDiff::of("a\nb\nc\nd\ne\nf\ng\nh\ni\nj\n", "a\nb\nc\nd\nE\nf\ng\nh\ni\nj\n", 'x.warrant'))->toBe(<<<'DIFF'
        --- a/x.warrant
        +++ b/x.warrant
        @@ -2,7 +2,7 @@
         b
         c
         d
        -e
        +E
         f
         g
         h

        DIFF)
        ->and(UnifiedDiff::of('same', 'same', 'x.warrant'))->toBe('');
});
