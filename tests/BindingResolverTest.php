<?php

use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\BindingResolver;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * The exception parsing $source with $bindings throws.
 *
 * @param array<int|string, mixed> $bindings
 */
function bindingError(string $source, array $bindings): WarrantSyntaxException
{
    try {
        WarrantParser::parse($source, $bindings);
    } catch (WarrantSyntaxException $error) {
        return $error;
    }

    throw new RuntimeException('Expected the parse to throw.');
}

it('gives positional values in the order the placeholders were written', function () {
    $check = WarrantParser::parse('check(a(?) for folders(?))', [1, 2])->conditionExpression();

    expect($check->predicate)->toEqual(new ConditionNode('a', [1]))
        ->and($check->boundKey)->toBe([2]);
});

it('points a binding error at the binding it is about', function () {
    $error = bindingError("if a\n  and b(:missing) they can view", []);

    expect($error->reason)->toBe('No binding provided for ":missing".')
        ->and($error->offset)->toBe(13)
        ->and($error->sourceLine)->toBe(2)
        ->and($error->sourceColumn)->toBe(9);
});

it('points an unused binding at the end of the text', function () {
    $error = bindingError("if a they can view\n", ['unused' => 1]);

    expect($error->offset)->toBe(19)
        ->and($error->sourceLine)->toBe(2)
        ->and($error->sourceColumn)->toBe(1);
});

it('reports a value that cannot stand where its binding was written, at the binding', function () {
    $error = bindingError('they cannot view because :why', ['why' => 5]);

    expect($error->reason)->toBe('A denial message must be a string or a closure, got int.')
        ->and($error->sourceColumn)->toBe(26);
});

it('reports binding errors in the order the bindings were written', function () {
    expect(bindingError('if a(@sql :query, :missing) they can view', ['query' => 5])->reason)
        ->toBe('An @sql binding must resolve to a string, got int.');
});

it('hands back a tree with no bindings as it is', function () {
    $written = WarrantParser::parseWithPositions('if a(1) they can view');

    expect((new BindingResolver('if a(1) they can view', $written->positions))->resolve($written, []))
        ->toBe($written);
});

it('keeps the tree as written beside the resolved one, with the positions describing it', function () {
    $source = 'if a(:x) and b they can view';
    $syntax = WarrantParser::parse($source, ['x' => 5]);
    $written = $syntax->written[0]->conditions;
    $span = $syntax->positions->spanOf($written->leftSide, ConditionNode::PART_PARAMETERS, 0);

    expect($syntax->children[0]->conditions->leftSide)->toEqual(new ConditionNode('a', [5]))
        ->and($written->leftSide)->toEqual(new ConditionNode('a', [new BindingNode('x')]))
        ->and(substr($source, $span->offset, $span->length()))->toBe(':x');
});
