<?php

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\AnalysisResult;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Validation\RuleValidationException;
use Warrant\DSL\Parsing\Validation\WarrantSyntaxValidator;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\SchemaCatalog;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Facades\Warrant;
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
    expect(fn () => Warrant::validate(WarrantSyntax::parse('for course_sections { if is_teacher they can view }')))
        ->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => Warrant::validate(WarrantSyntax::parse("for course_sections {\n  they can frobnicate\n}")))
        ->toThrow(RuleValidationException::class, 'Ability [frobnicate] is not declared by the schema. (line 2, column 12)');
});

it('reports a for header that names no schema, at the name', function () {
    expect(fn () => Warrant::validate(WarrantSyntax::parse('for nope { they can view }')))
        ->toThrow(RuleValidationException::class, 'The `for` header names unknown schema [nope]. (line 1, column 5)');
});

it('checks rules with no header only against a schema it is given', function () {
    $syntax = WarrantSyntax::parse('if no_such_condition they can view');

    expect(fn () => (new WarrantSyntaxValidator)->validateWritten($syntax))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new WarrantSyntaxValidator(new WarrantTestSchema, 'course_sections'))->validateWritten($syntax))
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
        (new WarrantSyntaxValidator(new WarrantTestSchema, 'course_sections'))->diagnose(WarrantParser::analyze($source)->syntax),
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

    expect(fn () => (new WarrantSyntaxValidator(new WarrantTestSchema, 'course_sections'))->validateWritten(WarrantSyntax::parse($source)))
        ->toThrow(RuleValidationException::class, validationDiagnostics($source)[0][0]);
});

/**
 * A catalog holding only $schemas, by key, with nothing behind it.
 *
 * @param array<string, SchemaVocabulary> $schemas
 */
function catalogOf(array $schemas): SchemaCatalog
{
    return new class($schemas) implements SchemaCatalog
    {
        /** @param array<string, SchemaVocabulary> $schemas */
        public function __construct(private array $schemas) {}

        public function find(string $schemaKey): ?SchemaVocabulary
        {
            return $this->schemas[$schemaKey] ?? null;
        }
    };
}

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

