<?php

use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\InvalidSyntax;
use Warrant\DSL\Parsing\ASTNodes\SyntaxBindingsResolved;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Validation\GenericMode;
use Warrant\DSL\Parsing\Validation\RuleValidationException;
use Warrant\DSL\Parsing\Validation\ValidationInput;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\Registry\RegisteredSchemas;

require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

/**
 * Input validating rules with no header against the registered test schema.
 */
function registeredInput(?string $schema = 'course_sections', GenericMode $mode = GenericMode::RequireNotGeneric): ValidationInput
{
    return new ValidationInput(new RegisteredSchemas, $schema, $mode);
}

// -- each stage is made only from the one before it --------------------------

it('makes each stage only through its own factory', function (string $stage) {
    expect((new ReflectionClass($stage))->getConstructor()->isPrivate())->toBeTrue();
})->with([ValidSyntax::class, SyntaxBindingsResolved::class, ExpandedSyntax::class]);

it('makes each stage only from the stage before it', function (string $stage, string $from) {
    $parameter = (new ReflectionMethod($stage, 'of'))->getParameters()[0];

    expect((string) $parameter->getType())->toBe($from);
})->with([
    'resolved from valid' => [SyntaxBindingsResolved::class, ValidSyntax::class],
    'expanded from resolved' => [ExpandedSyntax::class, SyntaxBindingsResolved::class],
]);

// -- resolving bindings -------------------------------------------------------

it('validates a syntax before it puts any value in place', function () {
    // Both a mistake in the text and a binding with no value: the mistake is
    // what is reported, since the text is validated first.
    $syntax = WarrantSyntax::parse('if is_teacher(:missing) they can view if is_advisor they can', []);

    expect(fn () => $syntax->resolveBindings())
        ->toThrow(RuleValidationException::class, 'This clause names none, but the rules before it name theirs');
});

it('puts the values in place once the text is valid', function () {
    expect(fn () => WarrantSyntax::parse('if is_teacher(:missing) they can view', [])->resolveBindings())
        ->toThrow(WarrantSyntaxException::class, 'No binding provided for ":missing"');
});

it('resolves a valid syntax under the input it was validated with', function () {
    $input = registeredInput();
    $resolved = ValidSyntax::of(WarrantSyntax::parse('if is_teacher(:x) they can view', ['x' => 5]), $input)->resolveBindings();

    expect($resolved)->toBeInstanceOf(SyntaxBindingsResolved::class)
        ->and($resolved->input)->toBe($input)
        ->and($resolved->rule()->conditions->parameters)->toBe([5])
        ->and($resolved->resolveBindings())->toBe($resolved);
});

it('resolves a plain syntax under no schema, holding it only to the rules of the language', function () {
    $resolved = WarrantSyntax::parse('if no_such_condition they can view')->resolveBindings();

    expect($resolved->input)->toEqual(new ValidationInput(null));
});

it('throws the first mistake of an invalid syntax rather than resolve it', function () {
    $checked = ValidSyntax::check(WarrantSyntax::parse('if is_teachr they can view'), registeredInput());

    expect($checked)->toBeInstanceOf(InvalidSyntax::class)
        ->and(fn () => $checked->resolveBindings())
        ->toThrow(RuleValidationException::class, 'Condition [is_teachr] is not declared by the schema.');
});

// -- expanding ----------------------------------------------------------------

/**
 * $source taken through every stage under $input.
 */
function expandedSyntax(string $source, ValidationInput $input): ExpandedSyntax
{
    return ExpandedSyntax::of(ValidSyntax::of(WarrantSyntax::parse($source), $input)->resolveBindings());
}

it('expands rules with no header against the schema they were validated in', function () {
    $expanded = expandedSyntax('can they view, update { if is_teacher they can }', registeredInput());

    expect($expanded->schemaKey)->toBe('course_sections')
        ->and($expanded->rules)->toHaveCount(1)
        ->and($expanded->rules[0]->canAbilities())->toBe(['view', 'update']);
});

it('expands a for body against the schema its header names', function () {
    expect(expandedSyntax('for course_sections { if is_teacher they can view }', registeredInput(null))->schemaKey)
        ->toBe('course_sections');
});

it('refuses to expand rules with no header validated in no schema', function () {
    expect(fn () => expandedSyntax('if is_teacher they can view', registeredInput(null, GenericMode::Either)))
        ->toThrow(InvalidArgumentException::class, 'validated in none');
});

it('refuses to expand rules validated with no catalog to find a schema in', function () {
    expect(fn () => ExpandedSyntax::of(WarrantSyntax::parse('if is_teacher they can view')->resolveBindings()))
        ->toThrow(InvalidArgumentException::class, 'no schemas to be expanded against');
});

it('has no bindings left to resolve once expanded', function () {
    expect(fn () => expandedSyntax('if is_teacher they can view', registeredInput())->resolveBindings())
        ->toThrow(LogicException::class, 'no bindings to resolve');
});

it('combines expanded rules for one schema, in order', function () {
    $first = expandedSyntax('if is_teacher they can view', registeredInput());
    $second = expandedSyntax('they cannot archive', registeredInput());

    $combined = ExpandedSyntax::combine('course_sections', $first, $second);

    expect($combined->rules)->toBe([...$first->rules, ...$second->rules]);
});

it('refuses to combine expanded rules for another schema', function () {
    expect(fn () => ExpandedSyntax::combine('lessons', expandedSyntax('if is_teacher they can view', registeredInput())))
        ->toThrow(InvalidArgumentException::class, 'cannot be combined into rules for [lessons]');
});
