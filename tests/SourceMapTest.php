<?php

use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\Positions\SourceEntry;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\DSL\Parsing\Positions\Span;

/**
 * The tokens of `can(view for docs(1, 2))`, and a map recording the can(...)
 * node, its ability, its schema and both row-key arguments.
 *
 * @return array{0: SourceMap, 1: CrossSchemaCanNode, 2: list<Token>}
 */
function mappedCan(): array
{
    $tokens = (new Lexer('can(view for docs(1, 2))'))->tokenize();
    // 0 can, 1 (, 2 view, 3 for, 4 docs, 5 (, 6 1, 7 ",", 8 2, 9 ), 10 ), 11 EOF

    $node = new CrossSchemaCanNode('docs', 'view', true, [1, 2]);
    $map = new SourceMap;

    $map->record($node, $tokens[0], $tokens[10]);
    $map->recordPart($node, 'ability', null, $tokens[2]);
    $map->recordPart($node, 'schemaKey', null, $tokens[4]);
    $map->recordPart($node, 'boundKey', 0, $tokens[6]);
    $map->recordPart($node, 'boundKey', 1, $tokens[8]);

    return [$map, $node, $tokens];
}

/**
 * @param list<SourceEntry> $entries
 * @return list<array{0: ?string, 1: int|string|null}>
 */
function entryParts(array $entries): array
{
    return array_map(static fn (SourceEntry $entry): array => [$entry->part, $entry->key], $entries);
}

it('finds where a node and each of its parts were written', function () {
    [$map, $node] = mappedCan();

    expect($map->spanOf($node))->toEqual(new Span(0, 24))
        ->and($map->spanOf($node, 'ability'))->toEqual(new Span(4, 8))
        ->and($map->spanOf($node, 'schemaKey'))->toEqual(new Span(13, 17))
        ->and($map->spanOf($node, 'boundKey', 0))->toEqual(new Span(18, 19))
        ->and($map->spanOf($node, 'boundKey', 1))->toEqual(new Span(21, 22));
});

it('spans a part written over several tokens from the first to the last', function () {
    $tokens = (new Lexer('f(@context org)'))->tokenize();
    $node = new ConditionNode('f');
    $map = new SourceMap;

    $map->recordPart($node, 'parameters', 0, $tokens[2], $tokens[3]);

    expect($map->spanOf($node, 'parameters', 0))->toEqual(new Span(2, 14));
});

it('knows nothing of a node or part it did not record', function () {
    [$map, $node] = mappedCan();

    expect($map->spanOf(new CrossSchemaCanNode('docs', 'view', true, [1, 2])))->toBeNull()
        ->and($map->spanOf($node, 'alias'))->toBeNull()
        ->and($map->spanOf($node, 'boundKey', 2))->toBeNull()
        ->and($map->spanOf($node, 'boundKey'))->toBeNull();
});

it('tells apart equal nodes written in different places', function () {
    $tokens = (new Lexer('is_owner or is_owner'))->tokenize();
    $first = new ConditionNode('is_owner');
    $second = new ConditionNode('is_owner');
    $map = new SourceMap;

    $map->record($first, $tokens[0], $tokens[0]);
    $map->record($second, $tokens[2], $tokens[2]);

    expect($first)->toEqual($second)
        ->and($map->spanOf($first))->toEqual(new Span(0, 8))
        ->and($map->spanOf($second))->toEqual(new Span(12, 20));
});

it('lists what an offset is inside, widest first', function () {
    [$map] = mappedCan();

    expect(entryParts($map->containing(6)))->toBe([[null, null], ['ability', null]])
        ->and(entryParts($map->containing(1)))->toBe([[null, null]])
        ->and(entryParts($map->containing(18)))->toBe([[null, null], ['boundKey', 0]])
        ->and($map->containing(25))->toBe([]);
});

it('counts the offset just past a part as on it, where a cursor sits after typing it', function () {
    [$map] = mappedCan();

    expect(entryParts($map->containing(8)))->toBe([[null, null], ['ability', null]]);
});

it('puts a node before its own part when they cover the same text', function () {
    $tokens = (new Lexer('is_owner'))->tokenize();
    $node = new ConditionNode('is_owner');
    $map = new SourceMap;

    $map->recordPart($node, 'conditionKey', null, $tokens[0]);
    $map->record($node, $tokens[0], $tokens[0]);

    expect(entryParts($map->containing(3)))->toBe([[null, null], ['conditionKey', null]]);
});

it('refuses to record where something was written twice', function () {
    [$map, $node, $tokens] = mappedCan();

    expect(fn () => $map->recordPart($node, 'ability', null, $tokens[2]))
        ->toThrow(LogicException::class, 'Where '.CrossSchemaCanNode::class.' part [ability] was written is already recorded.');
});
