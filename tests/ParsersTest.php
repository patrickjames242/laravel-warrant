<?php

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseCondition;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseNegation;
use Warrant\DSL\Parsing\Grammar\ParseSyntax;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ParsingState;
use Warrant\DSL\Parsing\Positions\SourceEntry;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\DSL\Parsing\WarrantParser;

/**
 * Parse $source with $root, and the map of where it was written.
 *
 * @return array{0: mixed, 1: SourceMap}
 */
function runParser(Parser $root, string $source): array
{
    $state = ParsingState::forSource($source);
    $result = Parser::run($root, $state);
    $state->commitTo($map = new SourceMap);

    return [$result, $map];
}

it('reads the first of several parsers that starts here', function () {
    $negationOrCondition = new class extends ParseOneOf
    {
        protected static function parsers(): array
        {
            return [ParseNegation::class, ParseCondition::class];
        }
    };

    [$node, $map] = runParser($negationOrCondition, 'a(5)');

    expect($node)->toEqual(new ConditionNode('a', [5]))
        ->and(array_map(static fn (SourceEntry $entry) => $entry->node, $map->containing(2)))
        ->toBe([$node, $node]);
});

it('reads a null argument as a value, not as nothing read', function () {
    [$syntax, $map] = runParser(new ParseSyntax, 'a(null, ?, 1)');
    $node = $syntax->conditionExpression();

    expect($node)->toEqual(new ConditionNode('a', [null, new BindingNode, 1]))
        ->and(WarrantSyntax::parse('a(null, ?, 1)', [null])->conditionExpression())
        ->toEqual(new ConditionNode('a', [null, null, 1]))
        ->and($map->spanOf($node, ConditionNode::PART_PARAMETERS, 0)?->offset)->toBe(2)
        ->and($map->spanOf($node, ConditionNode::PART_PARAMETERS, 1)?->offset)->toBe(8)
        ->and($map->spanOf($node, ConditionNode::PART_PARAMETERS, 2)?->offset)->toBe(11);
});

it('reads repeated items until one does not start, and none when none does', function () {
    $root = new class extends Parser
    {
        protected function read(): mixed
        {
            return [$this->parseRepeated(ParseCondition::class), $this->parseRepeated(ParseCondition::class)];
        }
    };

    [[$conditions, $none]] = runParser($root, 'a b(1) c');

    expect($conditions)->toEqual([new ConditionNode('a'), new ConditionNode('b', [1]), new ConditionNode('c')])
        ->and($none)->toBe([]);
});

it('refuses a repeated item that matches without reading anything', function () {
    $readsNothing = new class extends Parser
    {
        protected function read(): mixed
        {
            return [];
        }
    };

    $root = new class($readsNothing) extends Parser
    {
        public function __construct(private readonly Parser $item)
        {
        }

        protected function read(): mixed
        {
            return $this->parseRepeated($this->item);
        }
    };

    expect(fn () => runParser($root, 'a'))->toThrow(LogicException::class, 'matched without reading anything');
});

it('reads separated items, and reports an item missing after a separator', function (string $source, ?array $expected, bool $throws = false) {
    $root = new class extends Parser
    {
        protected function read(): mixed
        {
            return $this->parseSeparatedList(
                ParseCondition::class,
                TokenType::COMMA,
                fn () => new RuntimeException('Missing after a comma.'),
            );
        }
    };

    $throws
        ? expect(fn () => runParser($root, $source))->toThrow(RuntimeException::class, 'Missing after a comma.')
        : expect(runParser($root, $source)[0])->toEqual($expected);
})->with([
    'several' => ['a, b(1), c', [new ConditionNode('a'), new ConditionNode('b', [1]), new ConditionNode('c')]],
    'one' => ['a', [new ConditionNode('a')]],
    'none' => ['', []],
    'a trailing separator' => ['a, b,', null, true],
]);

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

it('starts one of several parsers at every token one of them starts at', function () {
    $conditionOrNegation = new class extends ParseOneOf
    {
        protected static function parsers(): array
        {
            return [ParseCondition::class, ParseNegation::class];
        }

        /** @return list<TokenType>|null */
        public static function startTokens(): ?array
        {
            return self::startsWith();
        }
    };

    $conditionOrAnything = new class extends ParseOneOf
    {
        protected static function parsers(): array
        {
            $startsAnywhere = new class extends Parser
            {
                protected function read(): mixed
                {
                    return null;
                }
            };

            return [ParseCondition::class, $startsAnywhere];
        }

        /** @return list<TokenType>|null */
        public static function startTokens(): ?array
        {
            return self::startsWith();
        }
    };

    expect($conditionOrNegation::startTokens())->toBe([TokenType::IDENTIFIER, TokenType::NOT])
        ->and($conditionOrAnything::startTokens())->toBeNull();
});

it('does not read a parser at a token it cannot start at', function () {
    $startsAtAParenthesis = new class extends Parser
    {
        protected static function startsWith(): TokenType
        {
            return TokenType::LPAREN;
        }

        protected function read(): mixed
        {
            throw new LogicException('Read at a token it cannot start at.');
        }
    };

    $root = new class($startsAtAParenthesis) extends Parser
    {
        public function __construct(private readonly Parser $item)
        {
        }

        protected function read(): mixed
        {
            return [$this->parse($this->item), $this->parse(ParseCondition::class)?->value];
        }
    };

    [[$parsed, $condition]] = runParser($root, 'a');

    expect($parsed)->toBeNull()
        ->and($condition)->toEqual(new ConditionNode('a'));
});
