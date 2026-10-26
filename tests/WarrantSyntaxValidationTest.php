<?php

use Warrant\Builders\Build;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\InvalidSyntax;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\Validation\GenericMode;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\Registry\RegisteredSchemas;
use Warrant\Rules\IRuleProvider;
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
    app()->instance(IRuleProvider::class, new class($rules) implements IRuleProvider
    {
        public function __construct(private mixed $rules) {}

        public function rules(RuleProviderContext $context): RuleSetNode|IRuleEntryNode|\Warrant\Builders\WarrantRuleBuilder|WarrantSyntax|string|iterable
        {
            return $this->rules;
        }
    });

    app(WarrantManager::class)->flush();

    try {
        Warrant::guard(makeWarrantTestUser())->forSchema(WarrantTestSchema::class)->expandedRuleSet();
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
    $error = providedRulesError(Build::rule()->theyCannot('frobnicate'));

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
        ->and(fn () => ValidSyntax::of(producedFor($syntax, 'course_sections'), new ValidationInput(new RegisteredSchemas)))
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
        mistakesOf(producedFor(WarrantParser::analyze($source)->syntax, 'course_sections'), new ValidationInput(new RegisteredSchemas)),
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

    expect(fn () => ValidSyntax::of(producedFor(WarrantSyntax::parse($source), 'course_sections'), new ValidationInput(new RegisteredSchemas)))
        ->toThrow(RuleValidationException::class, validationDiagnostics($source)[0][0]);
});

it('looks every schema up in the catalog it is given, and nowhere else', function () {
    $catalog = catalogOf(['lessons' => new WarrantTestSchema]);

    expect(analyzedDiagnostics('for lessons { if can(view for lessons) they can view }', $catalog))->toBe([])
        ->and(array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->message,
            analyzedDiagnostics('for course_sections { they can view }', $catalog),
        ))->toBe(['The `for` header names unknown schema [course_sections].']);
});

it('reports syntax errors and then the rules a text breaks, when analysed against a catalog', function () {
    $source = 'if is_teachr they can frobnicate ~';
    $diagnostics = analyzedDiagnostics("if is_teachr they can frobnicate\nthey can fly", catalogOf(['lessons' => new WarrantTestSchema]), 'lessons');

    expect(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $diagnostics))->toBe([
        'Ability [frobnicate] is not declared by the schema.',
        'Ability [fly] is not declared by the schema.',
        'Condition [is_teachr] is not declared by the schema.',
    ])->and(analyzedDiagnostics($source, catalogOf([]))[0]->message)->toBe("Unexpected character '~'.");
});

it('holds text to the rules that need no schema when analysed with no catalog', function () {
    expect(array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        analyzedDiagnostics("for nowhere { can they view { they can edit } they can because 'no' }", null),
    ))->toBe([
        'A can/cannot clause inside an ability block may not name abilities; the block header already names them.',
        'Every can/cannot clause and @include outside an ability block names the abilities it applies to; the rule set for [nowhere] holds one that names none.',
        "'because' may only follow a 'they cannot ...' clause, not 'they can ...'.",
    ]);
});

it('adds nothing of its own for a name an analysis reported missing', function (string $source, string $mistake) {
    $diagnostics = analyzedDiagnostics($source, catalogOf(['course_sections' => new WarrantTestSchema]), 'course_sections');

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

        expect(analyzedDiagnostics($source, $catalog, 'course_sections'))->toBeArray();
    }
});

it('says which kind of mistake each rule the schema cannot accept is', function (string $source, array $codes) {
    $diagnostics = analyzedDiagnostics($source, catalogOf(['course_sections' => new WarrantTestSchema]), 'course_sections');

    expect(array_map(static fn (Diagnostic $diagnostic): DiagnosticCode => $diagnostic->code, $diagnostics))->toBe($codes);
})->with([
    'an unknown condition and ability' => ['if is_teachr they can frobnicate', [DiagnosticCode::UnknownAbility, DiagnosticCode::UnknownCondition]],
    'an unknown schema' => ['for nowhere { they can view }', [DiagnosticCode::UnknownSchema]],
    'an unknown schema in a check' => ['if check(is_teacher for nowhere) they can view', [DiagnosticCode::UnknownSchema]],
    'a column not in scope' => ['if is_teacher(@column nowhere.id) they can view', [DiagnosticCode::ColumnNotInScope]],
]);


// -- the validation stage -----------------------------------------------------

/**
 * Input validating against the test schema alone.
 */
function courseSectionsInput(): ValidationInput
{
    return new ValidationInput(catalogOf(['course_sections' => new WarrantTestSchema]));
}

/**
 * $source parsed, with rules that have no header about the test schema, as
 * $mode says.
 */
function courseSectionsSyntax(string $source, GenericMode $mode = GenericMode::Either): WarrantSyntax
{
    return producedFor(WarrantSyntax::parse($source), 'course_sections', $mode);
}

/**
 * $syntax answered by a derived condition that the rule calling it passed
 * $callerColumnRef to.
 */
function answeredFor(WarrantSyntax $syntax, ColumnRef $callerColumnRef): WarrantSyntax
{
    $syntax->source->setProducer(SyntaxProducer::derivedCondition(
        'course_sections',
        WarrantTestSchema::class,
        'derived',
        'derived',
        new ConditionNode(nameNode('derived'), [$callerColumnRef], SyntaxLocation::noProducer()),
    ));

    return $syntax;
}

it('makes a valid syntax of text with no mistakes, holding the input it passed under with the producer it had', function () {
    $syntax = courseSectionsSyntax('if is_teacher they can view');
    $input = courseSectionsInput();
    $valid = ValidSyntax::of($syntax, $input);

    expect($valid->input->catalog)->toBe($input->catalog)
        ->and($valid->input->producer)->toBe($syntax->source->producer());
});

it('throws the first mistake rather than make a valid syntax', function () {
    expect(fn () => ValidSyntax::of(courseSectionsSyntax('if is_teachr they can view'), courseSectionsInput()))
        ->toThrow(RuleValidationException::class, 'Condition [is_teachr] is not declared by the schema.');
});

it('hands back a syntax already valid under an equal input as it is, and validates it again once handed over anew', function () {
    $valid = ValidSyntax::of(courseSectionsSyntax('if is_teacher they can view'), courseSectionsInput());

    expect(ValidSyntax::of($valid, courseSectionsInput()))->toBe($valid)
        ->and(ValidSyntax::of(producedFor($valid, 'course_sections', GenericMode::RequireNotGeneric), courseSectionsInput()))->not->toBe($valid);
});

it('leaves a @column the calling rule passed in unchecked in the scope it is passed into', function () {
    $callerColumnRef = new ColumnRef(nameNode('elsewhere'), nameNode('id'), SyntaxLocation::noProducer());
    $answer = fn (): WarrantSyntax => new WarrantSyntax([new ConditionNode(nameNode('is_teacher'), [$callerColumnRef], SyntaxLocation::noProducer())]);

    expect(fn () => ValidSyntax::of(producedFor($answer(), 'course_sections'), courseSectionsInput()))
        ->toThrow(RuleValidationException::class, 'A @column reference names [elsewhere], which is not in scope here')
        ->and(fn () => ValidSyntax::of(answeredFor($answer(), $callerColumnRef), courseSectionsInput()))
        ->not->toThrow(RuleValidationException::class);
});

it('checks a @column the calling rule did not pass in, even one equal to one it did', function () {
    $callerColumnRef = new ColumnRef(nameNode('elsewhere'), nameNode('id'), SyntaxLocation::noProducer());
    $equal = new ColumnRef(nameNode('elsewhere'), nameNode('id'), SyntaxLocation::noProducer());
    $answer = new WarrantSyntax([new ConditionNode(nameNode('is_teacher'), [$equal], SyntaxLocation::noProducer())]);

    expect(fn () => ValidSyntax::of(answeredFor($answer, $callerColumnRef), courseSectionsInput()))
        ->toThrow(RuleValidationException::class, 'A @column reference names [elsewhere], which is not in scope here');
});

it('hands back a syntax answered with a caller\'s column, already valid under an equal input, as it is', function () {
    $callerColumnRef = new ColumnRef(nameNode('elsewhere'), nameNode('id'), SyntaxLocation::noProducer());
    $syntax = answeredFor(new WarrantSyntax([new ConditionNode(nameNode('is_teacher'), [$callerColumnRef], SyntaxLocation::noProducer())]), $callerColumnRef);
    $valid = ValidSyntax::of($syntax, courseSectionsInput());

    expect(ValidSyntax::of($valid, courseSectionsInput()))->toBe($valid);
});

it('validates again under a different input, which may find the text invalid', function () {
    $valid = ValidSyntax::of(WarrantSyntax::parse('if no_such_condition they can view'), new ValidationInput(null));

    expect(fn () => ValidSyntax::of(producedFor($valid, 'course_sections'), courseSectionsInput()))
        ->toThrow(RuleValidationException::class, 'Condition [no_such_condition] is not declared by the schema.');
});

it('checks a text into an invalid syntax holding every mistake and the input', function () {
    $checked = ValidSyntax::check(courseSectionsSyntax('if is_teachr they can frobnicate'), courseSectionsInput());

    expect($checked)->toBeInstanceOf(InvalidSyntax::class)
        ->and($checked->input->producer?->schemaKey)->toBe('course_sections')
        ->and(array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $checked->diagnostics))->toBe([
            'Ability [frobnicate] is not declared by the schema.',
            'Condition [is_teachr] is not declared by the schema.',
        ]);
});

it('checks a text with no mistakes into a valid syntax', function () {
    expect(ValidSyntax::check(courseSectionsSyntax('if is_teacher they can view'), courseSectionsInput()))
        ->toBeInstanceOf(ValidSyntax::class);
});

/**
 * Every mistake $source makes under $mode, by message.
 *
 * @return list<string>
 */
function genericModeErrors(string $source, GenericMode $mode): array
{
    $checked = ValidSyntax::check(courseSectionsSyntax($source, $mode), courseSectionsInput());

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
    'named clause, required generic' => ['if is_teacher they can view', GenericMode::RequireGeneric, [LanguageRuleMessages::namedWhereGeneric('This can/cannot clause')]],
    'named include, required generic' => ['@include anything for view', GenericMode::RequireGeneric, [
        LanguageRuleMessages::namedWhereGeneric('This @include'),
        LanguageRuleMessages::unknownTemplate('course_sections', 'anything'),
    ]],
    'a block, required generic' => ['can they view { if is_teacher they can }', GenericMode::RequireGeneric, [LanguageRuleMessages::namedWhereGeneric('An ability block')]],
    'generic, either' => ['if is_teacher they can', GenericMode::Either, []],
    'named, either' => ['if is_teacher they can view', GenericMode::Either, []],
    'mixed, either' => ['if is_teacher they can view if is_advisor they can', GenericMode::Either, [LanguageRuleMessages::namingDisagrees('This can/cannot clause', false)]],
]);

it('holds a for body to naming its abilities whatever the mode', function () {
    expect(genericModeErrors('for course_sections { if is_teacher they can }', GenericMode::RequireGeneric))
        ->toBe([LanguageRuleMessages::unnamedOutsideBlock('course_sections')]);
});

it('checks a for body against the schema its header names, whatever schema the producer gives', function () {
    $syntax = producedFor(WarrantSyntax::parse('for course_sections { if is_teachr they can view }'), 'some_other_schema');

    expect(fn () => ValidSyntax::of($syntax, courseSectionsInput()))
        ->toThrow(RuleValidationException::class, 'Condition [is_teachr] is not declared by the schema.');
});

// -- validating a syntax ------------------------------------------------------

it('holds rules with no header and no schema only to the rules of the language', function () {
    expect(fn () => WarrantSyntax::parse('if no_such_condition they can')->validate())
        ->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => WarrantSyntax::parse('if is_teacher they can view if is_advisor they can')->validate())
        ->toThrow(RuleValidationException::class, LanguageRuleMessages::namingDisagrees('This can/cannot clause', false));
});

it('validates each for body against its registered schema', function () {
    expect(fn () => WarrantSyntax::parse("for course_sections {\n  they can frobnicate\n}")->validate())
        ->toThrow(RuleValidationException::class, 'Ability [frobnicate] is not declared by the schema. (line 2, column 12)');
});
