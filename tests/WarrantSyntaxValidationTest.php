<?php

use Warrant\DSL\Parsing\ASTNodes\InvalidSyntax;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\AnalysisResult;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Validation\GenericMode;
use Warrant\DSL\Parsing\Validation\LanguageRuleMessages;
use Warrant\DSL\Parsing\Validation\RuleValidationException;
use Warrant\DSL\Parsing\Validation\ValidationInput;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\SchemaCatalog;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Facades\Warrant;
use Warrant\Registry\RegisteredSchemas;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\WarrantManager;

require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

/**
 * The validation error a guard raises when the global provider returns $rules.
 */
function providedRulesError(mixed $rules): InvalidArgumentException
{
    app()->instance(RuleProvider::class, new class($rules) implements RuleProvider
    {
        public function __construct(private RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable $rules) {}

        public function rules(RuleProviderContext $context): RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable
        {
            return $this->rules;
        }
    });

    app(WarrantManager::class)->flush();

    try {
        Warrant::guard(makeWarrantTestUser())->forSchema(WarrantTestSchema::class)->resolvedRuleSet();
    } catch (InvalidArgumentException $error) {
        return $error;
    }

    throw new RuntimeException('Expected the provided rules to be rejected.');
}

it('points an error in provided rule text at the condition it is about', function () {
    $error = providedRulesError("if is_teacher they can view\nif is_teachr they can archive");

    expect($error)->toBeInstanceOf(RuleValidationException::class)
        ->and($error->reason)->toContain('is_teachr')
        ->and($error->position->line)->toBe(2)
        ->and($error->position->column)->toBe(4)
        ->and($error->getMessage())->toContain("(line 2, column 4)\n\n    if is_teachr they can archive\n       ^");
});

it('points an undeclared ability at the ability', function () {
    $error = providedRulesError('if is_teacher they can view, frobnicate');

    expect($error->reason)->toBe('Ability [frobnicate] is not declared by the schema.')
        ->and($error->position->column)->toBe(30);
});

it('points into the text an error is in, when a provider answers with several', function () {
    $error = providedRulesError(['if is_teacher they can view', "\n\nthey cannot frobnicate"]);

    expect($error->position->source)->toBe("\n\nthey cannot frobnicate")
        ->and($error->position->line)->toBe(3);
});

it('says only what is wrong with rules built in code, which have no text', function () {
    $error = providedRulesError(WarrantSyntax::parse('they cannot frobnicate')->rule());

    expect($error->getMessage())->toBe('Ability [frobnicate] is not declared by the schema.');
});

it('checks each for body against the schema its header names', function () {
    expect(fn () => validateRegistered(WarrantSyntax::parse('for course_sections { if is_teacher they can view }')))
        ->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => validateRegistered(WarrantSyntax::parse("for course_sections {\n  they can frobnicate\n}")))
        ->toThrow(RuleValidationException::class, 'Ability [frobnicate] is not declared by the schema. (line 2, column 12)');
});

it('reports a for header that names no schema, at the name', function () {
    expect(fn () => validateRegistered(WarrantSyntax::parse('for nope { they can view }')))
        ->toThrow(RuleValidationException::class, 'The `for` header names unknown schema [nope]. (line 1, column 5)');
});

it('checks rules with no header only against a schema it is given', function () {
    $syntax = WarrantSyntax::parse('if no_such_condition they can view');

    expect(fn () => ValidSyntax::of($syntax, new ValidationInput(new RegisteredSchemas)))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => ValidSyntax::of($syntax, new ValidationInput(new RegisteredSchemas, 'course_sections')))
        ->toThrow(RuleValidationException::class, 'no_such_condition');
});

/**
 * Every diagnostic for $source, read against the test schema, as [message, the
 * text it covers].
 *
 * @return list<array{0: string, 1: string}>
 */
function validationDiagnostics(string $source): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): array => [
            $diagnostic->message,
            substr($source, $diagnostic->offset, $diagnostic->endOffset - $diagnostic->offset),
        ],
        mistakesOf(WarrantParser::analyze($source)->syntax, new ValidationInput(new RegisteredSchemas, 'course_sections')),
    );
}

it('reports every mistake in a text, each over the text it is about', function () {
    expect(validationDiagnostics("if is_teachr they can view, frobnicate\nif is_teacher and nope they cannot fly"))->toBe([
        ['Ability [frobnicate] is not declared by the schema.', 'frobnicate'],
        ['Condition [is_teachr] is not declared by the schema.', 'is_teachr'],
        ['Ability [fly] is not declared by the schema.', 'fly'],
        ['Condition [nope] is not declared by the schema.', 'nope'],
    ]);
});

it('reports a for header that names no schema, and goes on to the bodies after it', function () {
    expect(validationDiagnostics('for nope { they can fly } for course_sections { they can frobnicate }'))->toBe([
        ['The `for` header names unknown schema [nope].', 'nope'],
        ['Ability [frobnicate] is not declared by the schema.', 'frobnicate'],
    ]);
});

it('reports nothing for a text with no mistakes', function () {
    expect(validationDiagnostics('if is_teacher they can view'))->toBe([]);
});

it('throws the first of the mistakes it would report', function () {
    $source = "if is_teachr they can view, frobnicate\nif nope they can view";

    expect(fn () => ValidSyntax::of(WarrantSyntax::parse($source), new ValidationInput(new RegisteredSchemas, 'course_sections')))
        ->toThrow(RuleValidationException::class, validationDiagnostics($source)[0][0]);
});

it('looks every schema up in the catalog it is given, and nowhere else', function () {
    $catalog = catalogOf(['lessons' => new WarrantTestSchema]);

    expect(WarrantParser::analyze('for lessons { if can(view for lessons) they can view }', $catalog)->diagnostics)->toBe([])
        ->and(array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->message,
            WarrantParser::analyze('for course_sections { they can view }', $catalog)->diagnostics,
        ))->toBe(['The `for` header names unknown schema [course_sections].']);
});

it('reports syntax errors and then the rules a text breaks, when analysed against a catalog', function () {
    $source = 'if is_teachr they can frobnicate ~';
    $diagnostics = WarrantParser::analyze("if is_teachr they can frobnicate\nthey can fly", catalogOf(['lessons' => new WarrantTestSchema]), 'lessons')->diagnostics;

    expect(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $diagnostics))->toBe([
        'Ability [frobnicate] is not declared by the schema.',
        'Ability [fly] is not declared by the schema.',
        'Condition [is_teachr] is not declared by the schema.',
    ])->and(WarrantParser::analyze($source, catalogOf([]))->diagnostics[0]->message)->toBe("Unexpected character '~'.");
});

it('reports bindings of both kinds at each one of the kind the first did not use', function () {
    $source = 'if a(:x, ?) and b(?) they can view';

    expect(array_map(
        static fn (Diagnostic $diagnostic): array => [$diagnostic->message, substr($source, $diagnostic->offset, $diagnostic->endOffset - $diagnostic->offset)],
        WarrantParser::analyze($source)->diagnostics,
    ))->toBe([
        ['Cannot mix named and positional bindings.', '?'],
        ['Cannot mix named and positional bindings.', '?'],
    ]);
});

it('holds text to the rules that need no schema when analysed with no catalog', function () {
    expect(array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        WarrantParser::analyze("for nowhere { can they view { they can edit } they can because 'no' }")->diagnostics,
    ))->toBe([
        'A clause inside an ability block may not name abilities; the block header already names them.',
        'Every clause and @include outside an ability block names the abilities it applies to; the rule set for [nowhere] holds one that names none.',
        "'because' may only follow a 'they cannot ...' clause, not 'they can ...'.",
    ]);
});

it('adds nothing of its own for a name an analysis reported missing', function (string $source, string $mistake) {
    $diagnostics = WarrantParser::analyze($source, catalogOf(['course_sections' => new WarrantTestSchema]), 'course_sections')->diagnostics;

    expect(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $diagnostics))->toBe([$mistake]);
})->with([
    'the schema of a for header' => ['for if is_teacher they can view', "Reserved word 'if' cannot be used as a name; expected a schema name after `for`."],
    'the ability of a can on this schema' => ['if can( ) they can view', 'Expected an ability name.'],
    'the ability of a can on another schema' => ['if can( for course_sections) they can view', "Reserved word 'for' cannot be used as a name; expected an ability name."],
    'the schema of a can' => ['if can(view for ) they can view', 'Expected a schema name.'],
    'the schema of a check' => ['if check(is_teacher for ) they can view', 'Expected a schema name.'],
]);

it('never throws while validating broken text against a schema', function () {
    $words = ['if', 'they', 'can', 'cannot', 'because', 'check', 'and', 'or', 'not', 'for', 'with', 'as',
        '(', ')', '{', '}', ',', '*', '=', '.', ':name', '?', '@context', '@column', '@sql', '@include',
        'is_teacher', 'is_advisor', 'via_join', 'view', 'edit', 'course_sections', 'nowhere', "'msg'", '1', 'org'];
    $catalog = catalogOf(['course_sections' => new WarrantTestSchema]);

    mt_srand(20261014);

    for ($i = 0; $i < 1500; $i++) {
        $source = implode(' ', array_map(static fn () => $words[mt_rand(0, count($words) - 1)], range(1, mt_rand(1, 16))));

        expect(WarrantParser::analyze($source, $catalog, 'course_sections'))->toBeInstanceOf(AnalysisResult::class);
    }
});

it('says which kind of mistake each rule the schema cannot accept is', function (string $source, array $codes) {
    $diagnostics = WarrantParser::analyze($source, catalogOf(['course_sections' => new WarrantTestSchema]), 'course_sections')->diagnostics;

    expect(array_map(static fn (Diagnostic $diagnostic): DiagnosticCode => $diagnostic->code, $diagnostics))->toBe($codes);
})->with([
    'an unknown condition and ability' => ['if is_teachr they can frobnicate', [DiagnosticCode::UnknownAbility, DiagnosticCode::UnknownCondition]],
    'an unknown schema' => ['for nowhere { they can view }', [DiagnosticCode::UnknownSchema]],
    'an unknown schema in a check' => ['if check(is_teacher for nowhere) they can view', [DiagnosticCode::UnknownSchema]],
    'a column not in scope' => ['if is_teacher(@column nowhere.id) they can view', [DiagnosticCode::ColumnNotInScope]],
]);


// -- the validation stage -----------------------------------------------------

/**
 * Input validating rules with no header against the test schema, as $mode says.
 */
function courseSectionsInput(GenericMode $mode = GenericMode::Either): ValidationInput
{
    return new ValidationInput(catalogOf(['course_sections' => new WarrantTestSchema]), 'course_sections', $mode);
}

it('makes a valid syntax of text with no mistakes, holding the input it passed under', function () {
    $input = courseSectionsInput();
    $valid = ValidSyntax::of(WarrantSyntax::parse('if is_teacher they can view'), $input);

    expect($valid->input)->toBe($input);
});

it('throws the first mistake rather than make a valid syntax', function () {
    expect(fn () => ValidSyntax::of(WarrantSyntax::parse('if is_teachr they can view'), courseSectionsInput()))
        ->toThrow(RuleValidationException::class, 'Condition [is_teachr] is not declared by the schema.');
});

it('hands back a syntax already valid under an equal input as it is', function () {
    $valid = ValidSyntax::of(WarrantSyntax::parse('if is_teacher they can view'), courseSectionsInput());

    expect(ValidSyntax::of($valid, courseSectionsInput()))->toBe($valid)
        ->and(ValidSyntax::of($valid, courseSectionsInput(GenericMode::RequireNotGeneric)))->not->toBe($valid);
});

it('validates again under a different input, which may find the text invalid', function () {
    $valid = ValidSyntax::of(WarrantSyntax::parse('if no_such_condition they can view'), new ValidationInput(null));

    expect(fn () => ValidSyntax::of($valid, courseSectionsInput()))
        ->toThrow(RuleValidationException::class, 'Condition [no_such_condition] is not declared by the schema.');
});

it('checks a text into an invalid syntax holding every mistake and the input', function () {
    $input = courseSectionsInput();
    $checked = ValidSyntax::check(WarrantSyntax::parse('if is_teachr they can frobnicate'), $input);

    expect($checked)->toBeInstanceOf(InvalidSyntax::class)
        ->and($checked->input)->toBe($input)
        ->and(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $checked->diagnostics))->toBe([
            'Ability [frobnicate] is not declared by the schema.',
            'Condition [is_teachr] is not declared by the schema.',
        ]);
});

it('checks a text with no mistakes into a valid syntax', function () {
    expect(ValidSyntax::check(WarrantSyntax::parse('if is_teacher they can view'), courseSectionsInput()))
        ->toBeInstanceOf(ValidSyntax::class);
});

/**
 * Every mistake $source makes under $mode, by message.
 *
 * @return list<string>
 */
function genericModeErrors(string $source, GenericMode $mode): array
{
    $checked = ValidSyntax::check(WarrantSyntax::parse($source), courseSectionsInput($mode));

    return $checked instanceof InvalidSyntax
        ? array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $checked->diagnostics)
        : [];
}

it('holds rules with no header to naming their abilities, or not, as the mode says', function (string $source, GenericMode $mode, array $errors) {
    expect(genericModeErrors($source, $mode))->toBe($errors);
})->with([
    'named, required named' => ['if is_teacher they can view', GenericMode::RequireNotGeneric, []],
    'generic, required named' => ['if is_teacher they can', GenericMode::RequireNotGeneric, [LanguageRuleMessages::unnamedOutsideBlock('course_sections')]],
    'generic, required generic' => ['if is_teacher they can', GenericMode::RequireGeneric, []],
    'named clause, required generic' => ['if is_teacher they can view', GenericMode::RequireGeneric, [LanguageRuleMessages::namedWhereGeneric('This clause')]],
    'named include, required generic' => ['@include anything for view', GenericMode::RequireGeneric, [
        LanguageRuleMessages::namedWhereGeneric('This @include'),
        LanguageRuleMessages::unknownTemplate('course_sections', 'anything'),
    ]],
    'a block, required generic' => ['can they view { if is_teacher they can }', GenericMode::RequireGeneric, [LanguageRuleMessages::namedWhereGeneric('An ability block')]],
    'generic, either' => ['if is_teacher they can', GenericMode::Either, []],
    'named, either' => ['if is_teacher they can view', GenericMode::Either, []],
    'mixed, either' => ['if is_teacher they can view if is_advisor they can', GenericMode::Either, [LanguageRuleMessages::namingDisagrees('This clause', false)]],
]);

it('holds a for body to naming its abilities whatever the mode', function () {
    expect(genericModeErrors('for course_sections { if is_teacher they can }', GenericMode::RequireGeneric))
        ->toBe([LanguageRuleMessages::unnamedOutsideBlock('course_sections')]);
});

it('checks a for body against the schema its header names, whatever schema the input gives', function () {
    $input = new ValidationInput(catalogOf(['course_sections' => new WarrantTestSchema]), 'some_other_schema');

    expect(fn () => ValidSyntax::of(WarrantSyntax::parse('for course_sections { if is_teachr they can view }'), $input))
        ->toThrow(RuleValidationException::class, 'Condition [is_teachr] is not declared by the schema.');
});

// -- validating a syntax ------------------------------------------------------

it('validates rules with no header as rules for the schema it is given', function () {
    expect(fn () => WarrantSyntax::parse('if is_teacher they can view')->validate('course_sections'))
        ->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => WarrantSyntax::parse('if is_teachr they can view')->validate('course_sections'))
        ->toThrow(RuleValidationException::class, 'Condition [is_teachr] is not declared by the schema. (line 1, column 4)');
});

it('holds rules for a schema to naming their abilities', function () {
    expect(fn () => WarrantSyntax::parse('if is_teacher they can')->validate('course_sections'))
        ->toThrow(RuleValidationException::class, LanguageRuleMessages::unnamedOutsideBlock('course_sections'));
});

it('holds rules with no header and no schema only to the rules of the language', function () {
    expect(fn () => WarrantSyntax::parse('if no_such_condition they can')->validate())
        ->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => WarrantSyntax::parse('if is_teacher they can view if is_advisor they can')->validate())
        ->toThrow(RuleValidationException::class, LanguageRuleMessages::namingDisagrees('This clause', false));
});

it('validates each for body against its registered schema, whatever schema it is given', function () {
    expect(fn () => WarrantSyntax::parse("for course_sections {\n  they can frobnicate\n}")->validate('elsewhere'))
        ->toThrow(RuleValidationException::class, 'Ability [frobnicate] is not declared by the schema. (line 2, column 12)');
});
