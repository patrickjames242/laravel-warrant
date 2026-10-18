<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Fragments\Fragment;
use Warrant\LanguageServer\Fragments\FragmentExtractor;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;

/**
 * The texts of the fragments of PHP $code.
 *
 * @return list<string>
 */
function fragmentTexts(string $code, ?VocabularyIndex $index = null): array
{
    return array_map(
        static fn (Fragment $fragment): string => $fragment->text,
        (new FragmentExtractor($index ?? new VocabularyIndex))->extract('/app/Example.php', $code),
    );
}

it('reads a .warrant file as one fragment, about the schema its marker names', function () {
    $fragments = (new FragmentExtractor(new VocabularyIndex))->extract('/x/rules.warrant', "# warrant-schema: docs\nthey can view");

    expect($fragments)->toHaveCount(1)
        ->and($fragments[0]->text)->toBe("# warrant-schema: docs\nthey can view")
        ->and($fragments[0]->schemaKey)->toBe('docs');
});

it('reads nothing from a file that is neither Warrant nor PHP', function () {
    expect((new FragmentExtractor(new VocabularyIndex))->extract('/x/readme.md', 'they can view'))->toBe([]);
});

it('reads a WARRANT heredoc or nowdoc wherever it stands, as PHP reads it', function () {
    $code = <<<'PHP'
        <?php
        $a = <<<'WARRANT'
            if a they can view
              they can edit
            WARRANT;
        $b = <<<WARRANT
            if {$x} they can view
            WARRANT;
        $c = <<<SQL
            select 1
            SQL;
        PHP;

    expect(fragmentTexts($code))->toBe(["if a they can view\n  they can edit", 'if      they can view']);
});

it('reads the rule text passed to the calls that take it, by position or by name', function () {
    $code = <<<'PHP'
        <?php
        warrant('a or b');
        Warrant::parse("they can \"view\"");
        \Warrant\DSL\Parsing\ASTNodes\WarrantSyntax::parse(syntax: 'they can edit');
        $rule->ifRaw('c')->orIfRaw('d');
        Warrant::parse(new BoundSyntax('if e(:x) they can view', ['x' => 1]));
        new \Warrant\DSL\Parsing\BoundSyntax(syntax: 'f');
        new Other('not rule text');
        other('not rule text');
        Warrant::parse('x' . $y);
        warrant($text, 'not first');
        PHP;

    expect(fragmentTexts($code))->toBe(['a or b', 'they can "view"', 'they can edit', 'c', 'd', 'if e(:x) they can view', 'f']);
});

it('reads a string returned from rules(), a template or a derived condition, alone or in an array', function () {
    $code = <<<'PHP'
        <?php
        class S extends WarrantSchema {
            public function rules(): array { return ['they can view', 'they can edit']; }
            #[RuleTemplate] public function t(): string { return 'they can share'; }
            #[DerivedCondition] public function d(): string { return 'a or b'; }
            public function other(): string { return 'not rule text'; }
            public function closure(): Closure { return fn () => 'not rule text'; }
        }
        class Plain {
            public function rules(): string { return 'not a provider'; }
        }
        PHP;

    expect(fragmentTexts($code))->toBe(['they can view', 'they can edit', 'they can share', 'a or b']);
});

it('reads a string written in a schema class as about that schema', function () {
    $code = <<<'PHP'
        <?php
        namespace App\Schemas;
        class FolderSchema extends BaseSchema {
            public function rules(): string { return 'they can view'; }
        }
        PHP;

    $fragments = (new FragmentExtractor(fixtureVocabulary()))->extract('/app/FolderSchema.php', $code);

    expect($fragments[0]->schemaKey)->toBe('folders');
});

it('maps every byte of a fragment back to the byte of the file it came from', function () {
    $code = "<?php\n\$a = <<<'WARRANT'\n    if a they\n      can view\n    WARRANT;\n\$b = 'it\\'s';\n";
    $fragments = (new FragmentExtractor(new VocabularyIndex))->extract('/app/x.php', $code);

    $heredoc = $fragments[0];
    expect($heredoc->text)->toBe("if a they\n  can view");

    for ($offset = 0; $offset < strlen($heredoc->text); $offset++) {
        expect($code[$heredoc->fileOffsetOf($offset)])->toBe($heredoc->text[$offset])
            ->and($heredoc->textOffsetOf($heredoc->fileOffsetOf($offset)))->toBe($offset);
    }
});

it('places the start of a range at the next run and its end at the run before', function () {
    $code = "<?php\n\$a = <<<'WARRANT'\n    ab\n    cd\n    WARRANT;\n";
    $fragment = (new FragmentExtractor(new VocabularyIndex))->extract('/app/x.php', $code)[0];
    $lineTwo = strpos($fragment->text, 'cd');

    expect(substr($code, $fragment->fileOffsetOf($lineTwo), 2))->toBe('cd')
        ->and($code[$fragment->fileOffsetOf(2, isEnd: true)])->toBe("\n");
});
