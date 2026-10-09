<?php

use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\SyntaxDiagnostic;
use Warrant\DSL\Parsing\WarrantSyntaxException;

const EVERY_TOKEN_KIND = <<<'WARRANT'
    for docs {
        # a comment
        if is_owner(:id, ?, @context org, @column docs.org_id, @sql "1 = 1")
            and not check(is_open for folders(@context folder) as f with k = -1.5)
            or !can(view for docs) they can view, * they cannot edit because 'no'
        can they share { they can }
        @include admin(true, null, 3)
    }
    WARRANT;

/**
 * @return list<array{0: TokenType, 1: string}>
 */
function lexedTokens(string $source): array
{
    return array_map(
        static fn (Token $token): array => [$token->type, $token->lexeme],
        (new Lexer($source))->scan()->tokens,
    );
}

/**
 * @return list<array{0: string, 1: int, 2: int}>
 */
function lexedDiagnostics(string $source): array
{
    return array_map(
        static fn (SyntaxDiagnostic $diagnostic): array => [$diagnostic->message, $diagnostic->offset, $diagnostic->endOffset],
        (new Lexer($source))->scan()->diagnostics,
    );
}

function strictLexError(string $source): WarrantSyntaxException
{
    try {
        (new Lexer($source))->tokenize();
    } catch (WarrantSyntaxException $e) {
        return $e;
    }

    throw new LogicException('The source lexed without an error.');
}

// -- token spans --------------------------------------------------------------

it('ends every token where its source text ends', function (string $source) {
    foreach ((new Lexer($source))->tokenize() as $token) {
        $text = substr($source, $token->offset, $token->endOffset() - $token->offset);

        expect($text)->toBe($token->lexeme);
    }
})->with([
    'every token kind' => [EVERY_TOKEN_KIND],
    'escapes' => ["if a('it\\'s', \"say \\\"hi\\\"\", 'back\\\\slash') they can view"],
    'multibyte text' => ["if a('café — ok') they cannot view because \"naïve\""],
    'a string spanning lines' => ["if a('one\ntwo') they can view"],
]);

it('measures the width of a token in bytes, not characters', function () {
    $string = (new Lexer("'café'"))->tokenize()[0];

    expect($string->type)->toBe(TokenType::STRING)
        ->and($string->offset)->toBe(0)
        ->and($string->endOffset())->toBe(7);
});

it('places the end of input at the end of the source, with no width', function () {
    $tokens = (new Lexer("they can view # trailing\n"))->tokenize();
    $eof = $tokens[array_key_last($tokens)];

    expect($eof->type)->toBe(TokenType::EOF)
        ->and($eof->offset)->toBe(25)
        ->and($eof->endOffset())->toBe(25);
});

it('ends a token at its own last byte, not at the trivia after it', function () {
    $tokens = (new Lexer("they   can\t\n view"))->tokenize();

    expect(array_map(static fn (Token $token): array => [$token->offset, $token->endOffset()], $tokens))
        ->toBe([[0, 4], [7, 10], [13, 17], [17, 17]]);
});

// -- strict reading -----------------------------------------------------------

it('quotes a multibyte unexpected character whole', function () {
    $e = strictLexError('if café they can view');

    expect($e->getMessage())->toStartWith("Unexpected character 'é'. (line 1, column 7)")
        ->and(json_encode($e->getMessage()))->not->toBeFalse();
});

it('names a byte that starts no UTF-8 character by its value', function () {
    $e = strictLexError("if a \xC3 they can view");

    expect($e->getMessage())->toStartWith('Unexpected byte 0xC3. (line 1, column 6)');
});

it('points an invalid escape at its backslash', function () {
    $e = strictLexError("if a('x\\n') they can view");

    expect($e->getMessage())->toStartWith('Invalid escape sequence "\\n"')
        ->and($e->offset)->toBe(7)
        ->and($e->sourceColumn)->toBe(8);
});

// -- tolerant reading ---------------------------------------------------------

it('scans sound text exactly as it tokenizes, with no diagnostics', function () {
    $scanned = (new Lexer(EVERY_TOKEN_KIND))->scan();

    expect($scanned->tokens)->toEqual((new Lexer(EVERY_TOKEN_KIND))->tokenize())
        ->and($scanned->diagnostics)->toBe([]);
});

it('makes text that could mean anything an error token', function (string $source, array $tokens, array $diagnostics) {
    expect(lexedTokens($source))->toBe($tokens)
        ->and(lexedDiagnostics($source))->toBe($diagnostics);
})->with([
    'an unexpected character' => [
        'a $ b',
        [[TokenType::IDENTIFIER, 'a'], [TokenType::ERROR, '$'], [TokenType::IDENTIFIER, 'b'], [TokenType::EOF, '']],
        [["Unexpected character '\$'.", 2, 3]],
    ],
    'a multibyte character, as one token' => [
        'caf€',
        [[TokenType::IDENTIFIER, 'caf'], [TokenType::ERROR, '€'], [TokenType::EOF, '']],
        [["Unexpected character '€'.", 3, 6]],
    ],
    'a byte that starts no character' => [
        "a\xFFb",
        [[TokenType::IDENTIFIER, 'a'], [TokenType::ERROR, "\xFF"], [TokenType::IDENTIFIER, 'b'], [TokenType::EOF, '']],
        [['Unexpected byte 0xFF.', 1, 2]],
    ],
    'a lone @' => [
        'f(@)',
        [[TokenType::IDENTIFIER, 'f'], [TokenType::LPAREN, '('], [TokenType::ERROR, '@'], [TokenType::RPAREN, ')'], [TokenType::EOF, '']],
        [["Expected 'context', 'column', 'sql', or 'include' after '@'.", 2, 3]],
    ],
    'an unknown @-word, with its word' => [
        'f(@contxt id)',
        [
            [TokenType::IDENTIFIER, 'f'], [TokenType::LPAREN, '('], [TokenType::ERROR, '@contxt'],
            [TokenType::IDENTIFIER, 'id'], [TokenType::RPAREN, ')'], [TokenType::EOF, ''],
        ],
        [["Expected 'context', 'column', 'sql', or 'include' after '@', got 'contxt'.", 2, 9]],
    ],
    'a : with no name' => [
        'f(:1)',
        [[TokenType::IDENTIFIER, 'f'], [TokenType::LPAREN, '('], [TokenType::ERROR, ':'], [TokenType::INT, '1'], [TokenType::RPAREN, ')'], [TokenType::EOF, '']],
        [["Expected a binding name after ':'.", 2, 3]],
    ],
    'a - with no digit' => [
        'f(-x)',
        [[TokenType::IDENTIFIER, 'f'], [TokenType::LPAREN, '('], [TokenType::ERROR, '-'], [TokenType::IDENTIFIER, 'x'], [TokenType::RPAREN, ')'], [TokenType::EOF, '']],
        [['Expected a digit after "-".', 2, 3]],
    ],
]);

it('repairs text whose meaning is plain into the token it was meant to be', function (string $source, array $tokens, array $values, array $diagnostics) {
    $scanned = (new Lexer($source))->scan();

    expect(lexedTokens($source))->toBe($tokens)
        ->and(array_map(static fn (Token $token): mixed => $token->value, $scanned->tokens))->toBe($values)
        ->and(lexedDiagnostics($source))->toBe($diagnostics);
})->with([
    'a number with nothing after its decimal point' => [
        'f(1.)',
        [[TokenType::IDENTIFIER, 'f'], [TokenType::LPAREN, '('], [TokenType::FLOAT, '1.'], [TokenType::RPAREN, ')'], [TokenType::EOF, '']],
        [null, null, 1.0, null, null],
        [['Expected a digit after the decimal point.', 2, 4]],
    ],
    'an invalid escape, kept as written' => [
        "'a\\nb'",
        [[TokenType::STRING, "'a\\nb'"], [TokenType::EOF, '']],
        ['a\\nb', null],
        [['Invalid escape sequence "\\n"; only \\\', \\", and \\\\ are allowed.', 2, 4]],
    ],
    'an invalid multibyte escape, as one character' => [
        "'\\é'",
        [[TokenType::STRING, "'\\é'"], [TokenType::EOF, '']],
        ['\\é', null],
        [['Invalid escape sequence "\\é"; only \\\', \\", and \\\\ are allowed.', 1, 4]],
    ],
    'an unterminated string on the last line, to the end' => [
        "they cannot view because 'no",
        [[TokenType::THEY, 'they'], [TokenType::CANNOT, 'cannot'], [TokenType::IDENTIFIER, 'view'], [TokenType::BECAUSE, 'because'], [TokenType::STRING, "'no"], [TokenType::EOF, '']],
        [null, null, null, null, 'no', null],
        [['Unterminated string literal.', 25, 26]],
    ],
    'an unterminated string, to its first line break' => [
        "because 'no\nthey can view",
        [[TokenType::BECAUSE, 'because'], [TokenType::STRING, "'no"], [TokenType::THEY, 'they'], [TokenType::CAN, 'can'], [TokenType::IDENTIFIER, 'view'], [TokenType::EOF, '']],
        [null, 'no', null, null, null, null],
        [['Unterminated string literal.', 8, 9]],
    ],
    'an unterminated string ending in a backslash' => [
        "'no\\",
        [[TokenType::STRING, "'no\\"], [TokenType::EOF, '']],
        ['no', null],
        [['Unterminated string literal.', 0, 1]],
    ],
]);

it('keeps a multi-line string that is closed whole', function () {
    expect(lexedTokens("f('one\ntwo')"))->toBe([
        [TokenType::IDENTIFIER, 'f'], [TokenType::LPAREN, '('], [TokenType::STRING, "'one\ntwo'"], [TokenType::RPAREN, ')'], [TokenType::EOF, ''],
    ])->and(lexedDiagnostics("f('one\ntwo')"))->toBe([]);
});

it('drops errors past the line break an unterminated string is ended at', function () {
    $source = "'a\\q\nf(@x\\z";

    expect(lexedTokens($source))->toBe([
        [TokenType::STRING, "'a\\q"], [TokenType::IDENTIFIER, 'f'], [TokenType::LPAREN, '('], [TokenType::ERROR, '@x'],
        [TokenType::ERROR, '\\'], [TokenType::IDENTIFIER, 'z'], [TokenType::EOF, ''],
    ])->and(lexedDiagnostics($source))->toBe([
        ['Unterminated string literal.', 0, 1],
        ['Invalid escape sequence "\\q"; only \\\', \\", and \\\\ are allowed.', 2, 4],
        ["Expected 'context', 'column', 'sql', or 'include' after '@', got 'x'.", 7, 9],
        ["Unexpected character '\\\\'.", 9, 10],
    ]);
});

it('agrees with a strict read on whether there is an error, and accounts for every byte', function (string $source) {
    $scanned = (new Lexer($source))->scan();
    $position = 0;

    foreach ($scanned->tokens as $token) {
        expect($token->offset)->toBeGreaterThanOrEqual($position)
            ->and(substr($source, $position, $token->offset - $position))->toMatch('/\A(?:\s+|#[^\n]*)*\z/')
            ->and(substr($source, $token->offset, $token->endOffset() - $token->offset))->toBe($token->lexeme);

        $position = $token->endOffset();
    }

    expect($scanned->tokens[array_key_last($scanned->tokens)]->type)->toBe(TokenType::EOF)
        ->and($position)->toBe(strlen($source));

    foreach ($scanned->diagnostics as $diagnostic) {
        expect(json_encode($diagnostic->message))->not->toBeFalse();
    }

    /* A strict read takes everything after an unclosed quote as the string, so
       the error it throws first can lie in text a tolerant scan reads as rules
       instead. Whether there is an error at all, they agree on. */
    try {
        $tokens = (new Lexer($source))->tokenize();
    } catch (WarrantSyntaxException) {
        expect($scanned->diagnostics)->not->toBe([]);

        return;
    }

    expect($tokens)->toEqual($scanned->tokens)
        ->and($scanned->diagnostics)->toBe([]);
})->with(function (): Generator {
    foreach (['every token kind' => EVERY_TOKEN_KIND, 'every error' => "a \$ 'b\\q\n @ @co :1 -x 1. é \xFF 'c\\"] as $name => $source) {
        for ($length = 0; $length <= strlen($source); $length++) {
            yield "$name, first $length bytes" => [substr($source, 0, $length)];
        }
    }

    mt_srand(20261009);
    $alphabet = ['a', 'z', '_', '-', '1', '.', ':', '@', '?', '#', "'", '"', '\\', '(', ')', '{', '}', ',', '*', '=', '!', ' ', "\n", "\r", '$', 'é', "\xC3", "\xFF", 'context', 'they', 'can'];

    for ($i = 0; $i < 300; $i++) {
        $source = '';

        for ($j = mt_rand(0, 30); $j > 0; $j--) {
            $source .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        yield "random $i" => [$source];
    }
});
