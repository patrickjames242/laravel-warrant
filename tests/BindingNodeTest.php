<?php

use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Validation\RuleValidationException;

require_once __DIR__.'/Support/TestSupport.php';

/**
 * The mistake validating $source with $bindings throws.
 *
 * @param array<int|string, mixed> $bindings
 */
function bindingError(string $source, array $bindings): RuleValidationException
{
    try {
        WarrantSyntax::parse(new BoundSyntax($source, $bindings))->validateWithoutSchemas();
    } catch (RuleValidationException $error) {
        return $error;
    }

    throw new RuntimeException('Expected the validation to throw.');
}

it('gives positional values in the order the placeholders were written', function () {
    $check = WarrantSyntax::parse(new BoundSyntax('check(a(?) for folders(?))', [1, 2]))->validateWithoutSchemas()->conditionExpression();

    expect($check->predicate->parameters[0]->index)->toBe(0)
        ->and($check->boundKey[0]->index)->toBe(1)
        ->and(argumentValues($check->predicate->parameters))->toBe([1])
        ->and(argumentValues($check->boundKey))->toBe([2]);
});

it('points a binding error at the binding it is about', function () {
    $error = bindingError("if a\n  and b(:missing) they can view", []);

    expect($error->reason)->toBe('No binding provided for ":missing".')
        ->and($error->span->offset)->toBe(13)
        ->and($error->position->line)->toBe(2)
        ->and($error->position->column)->toBe(9);
});

it('points an unused binding at the end of the text', function () {
    $error = bindingError("if a they can view\n", ['unused' => 1]);

    expect($error->span->offset)->toBe(19)
        ->and($error->position->line)->toBe(2)
        ->and($error->position->column)->toBe(1);
});

it('reports a value that cannot stand where its binding was written, at the binding', function () {
    $error = bindingError('they cannot view because :why', ['why' => 5]);

    expect($error->reason)->toBe('A denial message must be a string or a closure, got int.')
        ->and($error->position->column)->toBe(26);
});

it('reports binding errors in the order the bindings were written', function () {
    expect(bindingError('if a(@sql :query, :missing) they can view', ['query' => 5])->reason)
        ->toBe('An @sql binding must resolve to a string, got int.');
});

it('keeps each placeholder in the tree, its value looked up through the source it was written in', function () {
    $source = 'if a(:x) and b they can view';
    $binding = WarrantSyntax::parse(new BoundSyntax($source, ['x' => 5]))->validateWithoutSchemas()->rule()->conditions->leftSide->parameters[0];

    expect($binding)->toBeInstanceOf(BindingNode::class)
        ->and($binding->hasValue())->toBeTrue()
        ->and($binding->value())->toBe(5)
        ->and(substr($source, $binding->location->span->offset, $binding->location->span->length()))->toBe(':x');
});

it('has no value where its source gives none', function () {
    $binding = WarrantSyntax::parse('if a(:x) they can view')->children[0]->conditions->parameters[0];

    expect($binding->hasValue())->toBeFalse()
        ->and(fn () => $binding->value())->toThrow(LogicException::class, 'The binding [:x] has no value');
});
