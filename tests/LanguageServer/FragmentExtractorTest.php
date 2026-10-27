<?php

require_once __DIR__.'/Support.php';

use Warrant\DSL\Fragments\Fragment;
use Warrant\DSL\Fragments\FragmentExtractor;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;
use Warrant\PhpSource\RuleProviderClasses;

/**
 * The texts of the fragments of PHP $code, with the classes it declares, and
 * this library's, known.
 *
 * @return list<string>
 */
function fragmentTexts(string $code, ?VocabularyIndex $index = null): array
{
    $classes = (new RuleProviderClasses)->knowing('/app/Example.php', $code);

    return array_map(
        static fn (Fragment $fragment): string => $fragment->text,
        (new FragmentExtractor(($index ?? new VocabularyIndex)->keyOfClass(...), $classes->providesRules(...)))->extract('/app/Example.php', $code),
    );
}

it('reads a .warrant file as one fragment, about the schema its marker names', function () {
    $fragments = (new FragmentExtractor((new VocabularyIndex)->keyOfClass(...)))->extract('/x/rules.warrant', "# warrant-schema: docs\nthey can view");

    expect($fragments)->toHaveCount(1)
        ->and($fragments[0]->text)->toBe("# warrant-schema: docs\nthey can view")
        ->and($fragments[0]->schemaKey)->toBe('docs');
});

it('reads nothing from a file that is neither Warrant nor PHP', function () {
    expect((new FragmentExtractor((new VocabularyIndex)->keyOfClass(...)))->extract('/x/readme.md', 'they can view'))->toBe([]);
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
        $rule->andIfRaw('c2')->ifNotRaw('c3')->andIfNotRaw(expression: 'c4')->orIfNotRaw('c5');
        $rule->ifRawish('not rule text');
        Warrant::parse(new BoundSyntax('if e(:x) they can view', ['x' => 1]));
        new \Warrant\DSL\Parsing\BoundSyntax(syntax: 'f');
        new Other('not rule text');
        other('not rule text');
        Warrant::parse('x' . $y);
        warrant($text, 'not first');
        PHP;

    expect(fragmentTexts($code))->toBe(['a or b', 'they can "view"', 'they can edit', 'c', 'd', 'c2', 'c3', 'c4', 'c5', 'if e(:x) they can view', 'f']);
});

it('reads a string returned from rules(), a template or a derived condition, alone or in an array', function () {
    $code = <<<'PHP'
        <?php
        use Warrant\Schema\Types\Classes\ModelSchema;
        class S extends ModelSchema {
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

it('reads nothing from rules() on a class that provides no rules, such as a form request', function () {
    $code = <<<'PHP'
        <?php
        class StoreSlotRequest extends FormRequest {
            public function rules(): array
            {
                return [
                    'name' => 'string|required',
                    'start_time' => ['string', 'required'],
                    'account_number' => 'required', 'max:50',
                    'loan_total_amount' => $isExternal
                        ? 'required|numeric|min:0.01'
                        : 'nullable|numeric|min:0',
                ];
            }
        }
        PHP;

    expect(fragmentTexts($code))->toBe([]);
});

it('reads a string written in a schema class as about that schema', function () {
    $code = <<<'PHP'
        <?php
        namespace App\Schemas;
        class FolderSchema extends BaseSchema {
            public function rules(): string { return 'they can view'; }
        }
        PHP;

    $classes = RuleProviderClasses::ofProject(fixtureRoot())->knowing('/app/FolderSchema.php', $code);
    $fragments = (new FragmentExtractor((fixtureVocabulary())->keyOfClass(...), $classes->providesRules(...)))->extract('/app/FolderSchema.php', $code);

    expect($fragments[0]->schemaKey)->toBe('folders');
});

it('maps every byte of a fragment back to the byte of the file it came from', function () {
    $code = "<?php\n\$a = <<<'WARRANT'\n    if a they\n      can view\n    WARRANT;\n\$b = 'it\\'s';\n";
    $fragments = (new FragmentExtractor((new VocabularyIndex)->keyOfClass(...)))->extract('/app/x.php', $code);

    $heredoc = $fragments[0];
    expect($heredoc->text)->toBe("if a they\n  can view");

    for ($offset = 0; $offset < strlen($heredoc->text); $offset++) {
        expect($code[$heredoc->fileOffsetOf($offset)])->toBe($heredoc->text[$offset])
            ->and($heredoc->textOffsetOf($heredoc->fileOffsetOf($offset)))->toBe($offset);
    }
});

it('places the start of a range at the next run and its end at the run before', function () {
    $code = "<?php\n\$a = <<<'WARRANT'\n    ab\n    cd\n    WARRANT;\n";
    $fragment = (new FragmentExtractor((new VocabularyIndex)->keyOfClass(...)))->extract('/app/x.php', $code)[0];
    $lineTwo = strpos($fragment->text, 'cd');

    expect(substr($code, $fragment->fileOffsetOf($lineTwo), 2))->toBe('cd')
        ->and($code[$fragment->fileOffsetOf(2, isEnd: true)])->toBe("\n");
});

it('knows a class provides rules through the project classes and interfaces it extends', function () {
    $code = <<<'PHP'
        <?php
        namespace App\Rules;
        use Warrant\Rules\IRuleProvider;
        interface IPolicy extends \Countable, IRuleProvider {}
        class TeamRules implements IPolicy {}
        class FormRequestLike extends \Illuminate\Foundation\Http\FormRequest {}
        PHP;

    $classes = RuleProviderClasses::ofProject(fixtureRoot())->knowing('/app/Rules.php', $code);

    expect($classes->providesRules('App\\Schemas\\FolderSchema'))->toBeTrue()
        ->and($classes->providesRules('App\\Rules\\TeamRules'))->toBeTrue()
        ->and($classes->providesRules('App\\Rules\\FormRequestLike'))->toBeFalse()
        ->and($classes->providesRules('App\\Nowhere'))->toBeFalse()
        ->and($classes->providesRules('Warrant\\Schema\\Types\\Classes\\GlobalSchema'))->toBeFalse();
});
