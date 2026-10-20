<?php

use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\Positions\Span;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\WarrantParser;

require_once __DIR__.'/Support/TestSupport.php';

it('places a node and every name and literal it holds where each was written', function () {
    $node = WarrantParser::parse('can(view for docs(1, 2))')->children[0];

    expect($node->location->span)->toEqual(new Span(0, 24))
        ->and($node->ability->location->span)->toEqual(new Span(4, 8))
        ->and($node->schemaKey->location->span)->toEqual(new Span(13, 17))
        ->and($node->boundKey[0]->location->span)->toEqual(new Span(18, 19))
        ->and($node->boundKey[1]->location->span)->toEqual(new Span(21, 22));
});

it('spans a node written over several tokens from the first to the last', function () {
    $condition = WarrantParser::parse('f(@context org)')->children[0];

    expect($condition->parameters[0]->location->span)->toEqual(new Span(2, 14));
});

it('places every node of one text in the one source it was read from', function () {
    $syntax = WarrantParser::parse('can(view for docs(1, 2))');
    $node = $syntax->children[0];

    expect($node->location->source)->toBe($syntax->source)
        ->and($node->ability->location->source)->toBe($syntax->source);
});

it('tells apart equal nodes written in different places', function () {
    $or = WarrantParser::parse('is_owner or is_owner')->children[0];

    expect($or->leftSide)->toEqualSyntax($or->rightSide)
        ->and($or->leftSide->location->span)->toEqual(new Span(0, 8))
        ->and($or->rightSide->location->span)->toEqual(new Span(12, 20));
});

it('counts the offset just past a node as on it, where a cursor sits after typing it', function () {
    $node = WarrantParser::parse('can(view for docs(1, 2))')->children[0];

    expect($node->ability->location->span->contains(8))->toBeTrue()
        ->and($node->ability->location->span->contains(9))->toBeFalse();
});

it('has no span for a node built in code', function () {
    $node = new ConditionNode(nameNode('is_owner'), [], SyntaxLocation::noProducer());

    expect($node->location->isWritten())->toBeFalse()
        ->and($node->location->span)->toBeNull()
        ->and($node->location->source->producer())->toBeNull();
});
