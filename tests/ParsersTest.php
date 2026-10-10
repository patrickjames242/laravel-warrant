<?php

use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseCondition;
use Warrant\DSL\Parsing\Grammar\ParseSyntax;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ParsingState;
use Warrant\DSL\Parsing\Positions\SourceEntry;
use Warrant\DSL\Parsing\Positions\SourceMap;

/**
 * Parse $source with $root, and the map of where it was written.
 *
 * @param array<int|string, mixed> $bindings
 * @return array{0: mixed, 1: SourceMap}
 */
function runParser(Parser $root, string $source, array $bindings = []): array
{
    $state = ParsingState::forSource($source, $bindings);
    $result = Parser::run($root, $state);
    $state->commitTo($map = new SourceMap);

    return [$result, $map];
}

it('puts back the position, bindings and records of a parser that does not match', function () {
    $readsThenGivesUp = new class extends Parser
    {
        protected function read(): mixed
        {
            $this->parse(ParseCondition::class);

            return self::NOTHING;
        }
    };

    [$node, $map] = runParser(new ParseOneOf([$readsThenGivesUp, ParseCondition::class]), 'a(?)', [5]);

    // The second reading gets the binding the first one gave back, and only its
    // node and parts are in the map.
    expect($node)->toEqual(new ConditionNode('a', [5]))
        ->and(array_map(static fn (SourceEntry $entry) => $entry->node, $map->containing(2)))
        ->toBe([$node, $node]);
});

it('puts back everything read under lookahead, and answers with what was read', function () {
    $root = new class extends Parser
    {
        protected function read(): mixed
        {
            $ahead = $this->lookahead(ParseCondition::class)->value;

            return [$ahead, $this->parse(ParseCondition::class)->value];
        }
    };

    [[$ahead, $node], $map] = runParser($root, 'a(?)', [5]);

    expect($ahead)->toEqual($node)
        ->and($ahead)->not->toBe($node)
        ->and($map->spanOf($ahead))->toBeNull()
        ->and($map->spanOf($node))->not->toBeNull();
});

it('reads a null argument as a value, not as nothing read', function () {
    [$syntax, $map] = runParser(new ParseSyntax, 'a(null, ?, 1)', [null]);
    $node = $syntax->conditionExpression();

    expect($node)->toEqual(new ConditionNode('a', [null, null, 1]))
        ->and($map->spanOf($node, ConditionNode::PART_PARAMETERS, 0)?->offset)->toBe(2)
        ->and($map->spanOf($node, ConditionNode::PART_PARAMETERS, 1)?->offset)->toBe(8)
        ->and($map->spanOf($node, ConditionNode::PART_PARAMETERS, 2)?->offset)->toBe(11);
});

it('refuses a part read outside any node', function () {
    $root = new class extends Parser
    {
        protected function read(): mixed
        {
            $this->part(ConditionNode::PART_CONDITION_KEY, null, $this->advance());

            return true;
        }
    };

    expect(fn () => runParser($root, 'a'))->toThrow(LogicException::class, 'Part [conditionKey] was read outside any node.');
});
