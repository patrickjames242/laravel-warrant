<?php

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseCondition;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseNegation;
use Warrant\DSL\Parsing\Grammar\ParseSyntax;
use Warrant\DSL\Parsing\Parsers\ParseOneOf;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ParsingState;
use Warrant\DSL\Parsing\Parsers\TokensAhead;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Parsing\WarrantSyntaxException;

require_once __DIR__.'/Support/TestSupport.php';

/**
 * The state for reading $source.
 */
function parsingStateOf(string $source): ParsingState
{
    return ParsingState::forSource(new SyntaxSource(new BoundSyntax($source)));
}

/**
 * Parse $source with $root.
 *
 * @return array{0: mixed}
 */
function runParser(Parser $root, string $source): array
{
    return [Parser::run($root, parsingStateOf($source))];
}

it('reads the first of several parsers that starts here', function () {
    $negationOrCondition = new class extends ParseOneOf
    {
        protected static function parsers(): array
        {
            return [ParseNegation::class, ParseCondition::class];
        }
    };

    [$node] = runParser($negationOrCondition, 'a(5)');

    expect($node)->toEqualSyntax(new ConditionNode(nameNode('a'), [argumentNode(5)], SyntaxLocation::noProducer()))
        ->and($node->location->span->contains(2))->toBeTrue();
});

it('reads a null argument as a value, not as nothing read', function () {
    [[$node]] = runParser(new ParseSyntax, 'a(null, :v, 1)');

    expect($node)->toEqualSyntax(new ConditionNode(nameNode('a'), [argumentNode(null), new BindingNode('v', SyntaxLocation::noProducer()), argumentNode(1)], SyntaxLocation::noProducer()))
        ->and(argumentValues(WarrantSyntax::parse(new BoundSyntax('a(null, :v, 1)', ['v' => null]))->validateWithoutSchemas()->conditionExpression()->parameters))
        ->toBe([null, null, 1])
        ->and($node->parameters[0]->location->span->offset)->toBe(2)
        ->and($node->parameters[1]->location->span->offset)->toBe(8)
        ->and($node->parameters[2]->location->span->offset)->toBe(12);
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

    expect($conditions)->toEqualSyntax([new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), new ConditionNode(nameNode('b'), [argumentNode(1)], SyntaxLocation::noProducer()), new ConditionNode(nameNode('c'), [], SyntaxLocation::noProducer())])
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
                fn () => WarrantSyntaxException::atOffset(DiagnosticCode::MissingArgument, 'Missing after a comma.', '', 0, 1, 1),
            );
        }
    };

    $throws
        ? expect(fn () => runParser($root, $source))->toThrow(WarrantSyntaxException::class, 'Missing after a comma.')
        : expect(runParser($root, $source)[0])->toEqualSyntax($expected);
})->with([
    'several' => ['a, b(1), c', [new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), new ConditionNode(nameNode('b'), [argumentNode(1)], SyntaxLocation::noProducer()), new ConditionNode(nameNode('c'), [], SyntaxLocation::noProducer())]],
    'one' => ['a', [new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer())]],
    'none' => ['', []],
    'a trailing separator' => ['a, b,', null, true],
]);

it('starts one of several parsers wherever one of them starts', function (string $source, bool $conditionOrNegation, bool $conditionOrAnything) {
    $conditionOrNegationParser = new class extends ParseOneOf
    {
        protected static function parsers(): array
        {
            return [ParseCondition::class, ParseNegation::class];
        }

        public static function startsAt(TokensAhead $ahead): bool
        {
            return self::startsHere($ahead);
        }
    };

    $conditionOrAnythingParser = new class extends ParseOneOf
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

        public static function startsAt(TokensAhead $ahead): bool
        {
            return self::startsHere($ahead);
        }
    };

    $state = parsingStateOf($source);

    expect($conditionOrNegationParser::startsAt($state->tokensAhead))->toBe($conditionOrNegation)
        ->and($conditionOrAnythingParser::startsAt($state->tokensAhead))->toBe($conditionOrAnything);
})->with([
    'a condition' => ['a', true, true],
    'a negation' => ['not a', true, true],
    'neither' => ['(a)', false, true],
]);

it('tells whether the tokens ahead are the ones given, in order', function () {
    $parsingState = parsingStateOf('can they view');
    $state = $parsingState->tokensAhead;

    expect($state->nextIs(TokenType::CAN))->toBeTrue()
        ->and($state->nextIs(TokenType::THEY))->toBeFalse()
        ->and($state->nextIs(TokenType::CAN, TokenType::THEY))->toBeTrue()
        ->and($state->nextIs(TokenType::CAN, TokenType::LPAREN))->toBeFalse()
        ->and($state->nextIs(TokenType::CAN, TokenType::THEY, TokenType::IDENTIFIER, TokenType::EOF))->toBeTrue()
        ->and($state->nextIs(TokenType::CAN, TokenType::THEY, TokenType::IDENTIFIER, TokenType::EOF, TokenType::EOF))->toBeTrue();
});

it('does not read a parser at a token it cannot start at', function () {
    $startsAtAParenthesis = new class extends Parser
    {
        protected static function startsHere(TokensAhead $state): bool
        {
            return $state->nextIs(TokenType::LPAREN);
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
        ->and($condition)->toEqualSyntax(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()));
});
