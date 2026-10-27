<?php

use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedRule;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\InvalidSyntax;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Validation\GenericMode;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;

require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class, 'join_sections' => WarrantJoinConditionSchema::class]);
});

/**
 * $source validated against the registered schemas, its rules with no header
 * handed over as the test schema's own.
 */
function registeredValid(string|BoundSyntax $source): ValidSyntax
{
    return ValidSyntax::of(
        producedFor(WarrantSyntax::parse($source), 'course_sections', GenericMode::RequireNotGeneric),
        new ValidationInput(Warrant::registry()),
    );
}

/**
 * The rules about the test schema in $syntaxes, expanded.
 */
function expandedSyntax(ValidSyntax ...$syntaxes): ExpandedSyntax
{
    return ExpandedSyntax::expand('course_sections', new ValidationInput(Warrant::registry()), ...$syntaxes);
}

// -- each stage is made only from the one before it --------------------------

it('makes each stage only through its own factory', function (string $stage) {
    expect((new ReflectionClass($stage))->getConstructor()->isPrivate())->toBeTrue();
})->with([ValidSyntax::class, ExpandedSyntax::class]);

it('expands only syntaxes that were validated', function () {
    $parameter = (new ReflectionMethod(ExpandedSyntax::class, 'expand'))->getParameters()[2];

    expect((string) $parameter->getType())->toBe(ValidSyntax::class)
        ->and($parameter->isVariadic())->toBeTrue();
});

// -- validating ---------------------------------------------------------------

it('checks the values of the bindings while validating', function () {
    expect(fn () => registeredValid(new BoundSyntax('if is_teacher(:missing) they can view', [])))
        ->toThrow(RuleValidationException::class, 'No binding provided for ":missing"');
});

it('keeps each placeholder in the valid tree, its value looked up through its source', function () {
    $parameter = registeredValid(new BoundSyntax('if is_teacher(:x) they can view', ['x' => 5]))->rule()->conditions->parameters[0];

    expect($parameter)->toBeInstanceOf(BindingNode::class)
        ->and($parameter->value())->toBe(5);
});

it('validates a syntax with no schemas only against the rules of the language', function () {
    $valid = WarrantSyntax::parse('if no_such_condition they can view')->validateWithoutSchemas();

    expect($valid->input->catalog)->toBeNull()
        ->and($valid->input->producer)->toBeNull();
});

it('checks an invalid syntax into every mistake it makes', function () {
    $checked = ValidSyntax::check(
        producedFor(WarrantSyntax::parse('if is_teachr they can view'), 'course_sections'),
        new ValidationInput(Warrant::registry()),
    );

    expect($checked)->toBeInstanceOf(InvalidSyntax::class)
        ->and($checked->diagnostics[0]->message)->toBe('Condition [is_teachr] is declared by neither schema [course_sections] nor any global schema.');
});

// -- expanding ----------------------------------------------------------------

it('expands rules with no header against the schema their producer is for', function () {
    $expanded = expandedSyntax(registeredValid('can they view, update { if is_teacher they can }'));

    expect($expanded->schemaKey)->toBe('course_sections')
        ->and($expanded->ruleNodes())->toHaveCount(1)
        ->and($expanded->rules[0]->grantedAbilities())->toBe(['view', 'update']);
});

it('puts every binding\'s value in place', function () {
    $parameter = expandedSyntax(registeredValid(new BoundSyntax('if is_teacher(:x) they can view', ['x' => 5])))->ruleNodes()[0]->conditions->parameters[0];

    expect($parameter)->toBeInstanceOf(LiteralNode::class)
        ->and($parameter->value)->toBe(5);
});

it('expands a for body about the schema, whatever produced it', function () {
    $valid = WarrantSyntax::parse('for course_sections { if is_teacher they can view }')->validate();

    expect(expandedSyntax($valid)->ruleNodes())->toHaveCount(1);
});

it('leaves out rules with no header whose producer is for no schema', function () {
    expect(expandedSyntax(WarrantSyntax::parse('if is_teacher they can view')->validate())->ruleNodes())->toBe([]);
});

it('leaves out a rule set for another schema', function () {
    $valid = WarrantSyntax::parse('for join_sections { they can view } for course_sections { they cannot archive }')->validate();

    expect(expandedSyntax($valid)->ruleNodes())->toHaveCount(1)
        ->and(expandedSyntax($valid)->rules[0]->deniedAbilities())->toBe(['archive']);
});

it('joins the rules of every syntax, in order', function () {
    $expanded = expandedSyntax(registeredValid('if is_teacher they can view'), registeredValid('they cannot archive'));

    expect(array_map(static fn (ExpandedRule $rule): array => [...$rule->grantedAbilities(), ...$rule->deniedAbilities()], $expanded->rules))
        ->toBe([['view'], ['archive']]);
});

it('reads which abilities an expanded rule denies, and the message each is denied with', function () {
    $rule = expandedSyntax(registeredValid("they cannot view because 'Hidden.' they cannot archive"))->rules[0];

    expect($rule->denies('view'))->toBeTrue()
        ->and($rule->denies('update'))->toBeFalse()
        ->and($rule->messageFor('view'))->toBe('Hidden.')
        ->and($rule->messageFor('archive'))->toBeNull()
        ->and($rule->messageFor('update'))->toBeNull();
});

it('refuses to read the abilities of a rule that was never expanded', function (string|BoundSyntax $text) {
    $rule = new ExpandedRule(WarrantSyntax::parse($text)->validateWithoutSchemas()->rule());

    expect(fn () => $rule->deniedAbilities())->toThrow(LogicException::class, 'An expanded rule names every ability on its own');
})->with(['a wildcard' => ['they cannot *'], 'a binding' => [new BoundSyntax('they cannot :abilities', ['abilities' => ['view']])]]);

it('refuses to expand rules validated with no catalog to find a schema in', function () {
    expect(fn () => ExpandedSyntax::expand('course_sections', new ValidationInput(null), WarrantSyntax::parse('if is_teacher they can view')->validateWithoutSchemas()))
        ->toThrow(InvalidArgumentException::class, 'no schemas to be expanded against');
});
