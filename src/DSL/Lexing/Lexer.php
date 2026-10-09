<?php

namespace Warrant\DSL\Lexing;

use Warrant\DSL\Parsing\SyntaxDiagnostic;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * Turns raw Warrant syntax into a flat list of {@see Token}s.
 *
 * Whitespace (including newlines) is insignificant and simply separates tokens.
 * A `#` begins a line comment that runs to the end of the line (or the end of
 * the source); comments are trivia and never reach the parser. A `#` inside a
 * string literal is literal, since comments are only recognised between tokens.
 * String literals may be delimited by single (`'`) or double (`"`) quotes; the
 * closing quote must match the opener, and `\'`, `\"`, and `\\` are the escapes.
 * Keywords are matched case-sensitively in lower case: `if`, `they`, `can`,
 * `cannot`, `because`, `check`, `and`, `or`, `not`, `for`, `with`, `as`. `true` /
 * `false` / `null` are always lexed as literals, so they cannot double as
 * condition or ability names.
 *
 * ## Strict and tolerant reading
 *
 * {@see tokenize()} reads strictly: the first syntax error throws a
 * {@see WarrantSyntaxException}. {@see scan()} reads tolerantly, for an editor,
 * where the text is mid-edit and every error in it is wanted at once: each error
 * becomes a {@see SyntaxDiagnostic} and reading carries on. Both run the same
 * scanning code, which calls {@see report()} at every error; only what that does
 * differs, so the two readings cannot disagree about what is an error.
 *
 * A tolerant scan accounts for every byte of the source. Text whose meaning is
 * plain is repaired into the token it was meant to be — a string with an invalid
 * escape or a missing closing quote is still a STRING, `1.` still a FLOAT — and
 * text that could mean anything is an {@see TokenType::ERROR} token covering it.
 * Every token consumes at least one byte, so the scan always ends.
 */
final class Lexer
{
    private const KEYWORDS = [
        'if' => TokenType::IF,
        'they' => TokenType::THEY,
        'can' => TokenType::CAN,
        'cannot' => TokenType::CANNOT,
        'because' => TokenType::BECAUSE,
        'check' => TokenType::CHECK,
        'and' => TokenType::AND,
        'or' => TokenType::OR,
        'not' => TokenType::NOT,
        'for' => TokenType::FOR,
        'with' => TokenType::WITH,
        'as' => TokenType::AS,
    ];

    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;
    private readonly int $length;

    private bool $tolerant = false;

    /** @var list<SyntaxDiagnostic> */
    private array $diagnostics = [];

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * Read the source strictly, throwing at the first syntax error.
     *
     * @return list<Token>
     *
     * @throws WarrantSyntaxException
     */
    public function tokenize(): array
    {
        return $this->lex();
    }

    /**
     * Read the source tolerantly, reporting every syntax error instead of
     * throwing the first.
     */
    public function scan(): LexResult
    {
        $this->tolerant = true;

        return new LexResult($this->lex(), $this->diagnostics);
    }

    /**
     * @return list<Token>
     */
    private function lex(): array
    {
        $tokens = [];

        while (true) {
            $this->skipTrivia();

            if ($this->pos >= $this->length) {
                $tokens[] = $this->makeToken(TokenType::EOF, '');
                return $tokens;
            }

            $tokens[] = $this->scanToken();
        }
    }

    private function scanToken(): Token
    {
        $char = $this->source[$this->pos];

        return match (true) {
            $char === '(' => $this->single(TokenType::LPAREN),
            $char === ')' => $this->single(TokenType::RPAREN),
            $char === '{' => $this->single(TokenType::LBRACE),
            $char === '}' => $this->single(TokenType::RBRACE),
            $char === ',' => $this->single(TokenType::COMMA),
            $char === '*' => $this->single(TokenType::STAR),
            $char === '=' => $this->single(TokenType::EQUALS),
            $char === '.' => $this->single(TokenType::DOT),
            $char === '!' => $this->single(TokenType::NOT),
            $char === '?' => $this->single(TokenType::POSITIONAL),
            $char === ':' => $this->scanNamedBinding(),
            $char === '@' => $this->scanAtRef(),
            $char === "'" => $this->scanString(),
            $char === '"' => $this->scanString(),
            $this->isDigit($char) => $this->scanNumber(),
            $char === '-' => $this->scanNumber(),
            $this->isIdentifierStart($char) => $this->scanWord(),
            default => $this->scanUnexpected(),
        };
    }

    /**
     * Scan one character no token can start with, as an ERROR token.
     *
     * A character is a whole UTF-8 sequence, so a multibyte one is one error
     * rather than one per byte, and the message quotes it whole. A byte that
     * starts no valid sequence is named by its value instead, which keeps the
     * message itself valid UTF-8.
     */
    private function scanUnexpected(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $char = $this->characterAt($this->pos);

        $this->advanceBy(strlen($char));

        return $this->errorToken(
            $this->isValidUtf8($char)
                ? sprintf('Unexpected character %s.', var_export($char, true))
                : sprintf('Unexpected byte 0x%02X.', ord($char)),
            $startOffset,
            $startLine,
            $startCol,
        );
    }

    private function scanNamedBinding(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $this->advance(); // consume ':'

        if ($this->pos >= $this->length || ! $this->isIdentifierStart($this->source[$this->pos])) {
            return $this->errorToken("Expected a binding name after ':'.", $startOffset, $startLine, $startCol);
        }

        $name = $this->consumeIdentifier();

        return new Token(TokenType::NAMED_BINDING, ':' . $name, $startOffset, $startLine, $startCol, $name);
    }

    /**
     * Scan an `@`-prefixed word: the value references `@context` (a check-time
     * context value), `@column` (a schema-qualified database column) and `@sql`
     * (an arbitrary SQL fragment), or the directive `@include` (expand a rule
     * template). The word after `@` selects which; whatever follows — an
     * identifier for `@context`/`@column`/`@include`, a quoted string for `@sql` —
     * are separate tokens the parser reads.
     *
     * The sigil carries a directive as well as a value because an ability block's
     * header is a bare name: a keyword `include` would be reserved everywhere and
     * would collide with a block opened on an ability of that name.
     *
     * An unknown word is part of the ERROR token with its `@`. Left as a token of
     * its own it would read as a condition or ability name, and be reported a
     * second time as one that is not declared.
     */
    private function scanAtRef(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $this->advance(); // consume '@'

        if ($this->pos >= $this->length || ! $this->isIdentifierStart($this->source[$this->pos])) {
            return $this->errorToken(
                "Expected 'context', 'column', 'sql', or 'include' after '@'.",
                $startOffset,
                $startLine,
                $startCol,
            );
        }

        $word = $this->consumeIdentifier();

        return match ($word) {
            'context' => new Token(TokenType::CONTEXT_REF, '@context', $startOffset, $startLine, $startCol),
            'column' => new Token(TokenType::COLUMN_REF, '@column', $startOffset, $startLine, $startCol),
            'sql' => new Token(TokenType::SQL_REF, '@sql', $startOffset, $startLine, $startCol),
            'include' => new Token(TokenType::INCLUDE_REF, '@include', $startOffset, $startLine, $startCol),
            default => $this->errorToken(
                sprintf("Expected 'context', 'column', 'sql', or 'include' after '@', got '%s'.", $word),
                $startOffset,
                $startLine,
                $startCol,
            ),
        };
    }

    /**
     * Scan a string literal. A string may span lines.
     *
     * An invalid escape is reported over its two characters, and a tolerant scan
     * keeps them in the value as written and reads on: the string around it is
     * sound.
     *
     * A string with no closing quote runs to the end of the source. A tolerant
     * scan ends it at its first line break instead, when it has one, and lexes
     * what follows as rules again, so one missing quote does not turn the rest of
     * the source into a single string.
     */
    private function scanString(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $quote = $this->source[$this->pos];

        $this->advance(); // consume opening quote

        $value = '';
        $diagnosticsBefore = count($this->diagnostics);

        /* Where the string's first line ends, kept for when the string turns out
           to be unterminated: the position to resume lexing from, how much of the
           value lies before it, and how many errors had been reported by then. */
        $firstLineEnd = null;

        while (true) {
            if ($this->pos >= $this->length) {
                return $this->unterminatedString($startOffset, $startLine, $startCol, $value, $firstLineEnd, $diagnosticsBefore);
            }

            $char = $this->source[$this->pos];

            if ($char === "\n") {
                $firstLineEnd ??= $this->resumePoint($value);
            }

            if ($char === '\\') {
                $escapeOffset = $this->pos;
                $escapeLine = $this->line;
                $escapeCol = $this->col;

                $this->advance();

                if ($this->pos >= $this->length) {
                    return $this->unterminatedString($startOffset, $startLine, $startCol, $value, $firstLineEnd, $diagnosticsBefore);
                }

                $escaped = $this->characterAt($this->pos);

                if ($escaped === "\n") {
                    $firstLineEnd ??= $this->resumePoint($value);
                }

                $resolved = match ($escaped) {
                    "'" => "'",
                    '"' => '"',
                    '\\' => '\\',
                    default => null,
                };

                if ($resolved === null) {
                    $this->report(
                        sprintf(
                            'Invalid escape sequence "\\%s"; only \\\', \\", and \\\\ are allowed.',
                            $this->isValidUtf8($escaped) ? $escaped : sprintf('\\x%02X', ord($escaped)),
                        ),
                        $escapeOffset,
                        $escapeLine,
                        $escapeCol,
                        $this->pos + strlen($escaped),
                    );

                    $resolved = '\\' . $escaped;
                }

                $value .= $resolved;
                $this->advanceBy(strlen($escaped));
                continue;
            }

            if ($char === $quote) {
                $this->advance(); // consume closing quote
                $lexeme = substr($this->source, $startOffset, $this->pos - $startOffset);

                return new Token(TokenType::STRING, $lexeme, $startOffset, $startLine, $startCol, $value);
            }

            $value .= $char;
            $this->advance();
        }
    }

    /**
     * The string being scanned, reported as unterminated and ended at its first
     * line break when it has one. Errors reported past that line break are
     * dropped, because the text there is lexed again as rules; the ones before it
     * follow the unterminated-string error, which starts at the opening quote.
     *
     * @param array{offset: int, line: int, col: int, valueLength: int, diagnosticCount: int}|null $firstLineEnd
     * @param int $diagnosticsBefore How many errors had been reported when the
     *   string began.
     */
    private function unterminatedString(
        int $startOffset,
        int $startLine,
        int $startCol,
        string $value,
        ?array $firstLineEnd,
        int $diagnosticsBefore,
    ): Token {
        $kept = $firstLineEnd['diagnosticCount'] ?? count($this->diagnostics);
        $inString = array_slice($this->diagnostics, $diagnosticsBefore, $kept - $diagnosticsBefore);
        $this->diagnostics = array_slice($this->diagnostics, 0, $diagnosticsBefore);

        $this->report('Unterminated string literal.', $startOffset, $startLine, $startCol, $startOffset + 1);

        array_push($this->diagnostics, ...$inString);

        if ($firstLineEnd !== null) {
            $this->pos = $firstLineEnd['offset'];
            $this->line = $firstLineEnd['line'];
            $this->col = $firstLineEnd['col'];
            $value = substr($value, 0, $firstLineEnd['valueLength']);
        }

        $lexeme = substr($this->source, $startOffset, $this->pos - $startOffset);

        return new Token(TokenType::STRING, $lexeme, $startOffset, $startLine, $startCol, $value);
    }

    /**
     * The current position, as a place an unterminated string can be ended and
     * lexing resumed from.
     *
     * @return array{offset: int, line: int, col: int, valueLength: int, diagnosticCount: int}
     */
    private function resumePoint(string $value): array
    {
        return [
            'offset' => $this->pos,
            'line' => $this->line,
            'col' => $this->col,
            'valueLength' => strlen($value),
            'diagnosticCount' => count($this->diagnostics),
        ];
    }

    private function scanNumber(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        if ($this->source[$this->pos] === '-') {
            $this->advance();

            if ($this->pos >= $this->length || ! $this->isDigit($this->source[$this->pos])) {
                return $this->errorToken('Expected a digit after "-".', $startOffset, $startLine, $startCol);
            }
        }

        while ($this->pos < $this->length && $this->isDigit($this->source[$this->pos])) {
            $this->advance();
        }

        $isFloat = false;

        if ($this->pos < $this->length && $this->source[$this->pos] === '.') {
            $isFloat = true;
            $this->advance();

            // A tolerant scan reads `1.` as the number it plainly is.
            if ($this->pos >= $this->length || ! $this->isDigit($this->source[$this->pos])) {
                $this->report(
                    'Expected a digit after the decimal point.',
                    $startOffset,
                    $startLine,
                    $startCol,
                    $this->pos,
                );
            }

            while ($this->pos < $this->length && $this->isDigit($this->source[$this->pos])) {
                $this->advance();
            }
        }

        $lexeme = substr($this->source, $startOffset, $this->pos - $startOffset);
        $value = $isFloat ? (float) $lexeme : (int) $lexeme;

        return new Token($isFloat ? TokenType::FLOAT : TokenType::INT, $lexeme, $startOffset, $startLine, $startCol, $value);
    }

    private function scanWord(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $word = $this->consumeIdentifier();

        if (isset(self::KEYWORDS[$word])) {
            return new Token(self::KEYWORDS[$word], $word, $startOffset, $startLine, $startCol);
        }

        return match ($word) {
            'true' => new Token(TokenType::BOOL, $word, $startOffset, $startLine, $startCol, true),
            'false' => new Token(TokenType::BOOL, $word, $startOffset, $startLine, $startCol, false),
            'null' => new Token(TokenType::NULL, $word, $startOffset, $startLine, $startCol, null),
            default => new Token(TokenType::IDENTIFIER, $word, $startOffset, $startLine, $startCol),
        };
    }

    /**
     * Consume an identifier: a letter/underscore start, then any run of
     * letters, digits, underscores or dashes.
     */
    private function consumeIdentifier(): string
    {
        $start = $this->pos;
        $this->advance();

        while ($this->pos < $this->length && $this->isIdentifierPart($this->source[$this->pos])) {
            $this->advance();
        }

        return substr($this->source, $start, $this->pos - $start);
    }

    private function single(TokenType $type): Token
    {
        $token = $this->makeToken($type, $this->source[$this->pos]);
        $this->advance();

        return $token;
    }

    private function makeToken(TokenType $type, string $lexeme): Token
    {
        return new Token($type, $lexeme, $this->pos, $this->line, $this->col);
    }

    /**
     * Skip anything the parser never sees: whitespace and `#` line comments.
     */
    private function skipTrivia(): void
    {
        while ($this->pos < $this->length) {
            $char = $this->source[$this->pos];

            if (ctype_space($char)) {
                $this->advance();
                continue;
            }

            if ($char === '#') {
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $this->advance();
                }
                continue;
            }

            break;
        }
    }

    private function advance(): void
    {
        if ($this->source[$this->pos] === "\n") {
            $this->line++;
            $this->col = 1;
        } else {
            $this->col++;
        }

        $this->pos++;
    }

    private function advanceBy(int $bytes): void
    {
        for ($i = 0; $i < $bytes; $i++) {
            $this->advance();
        }
    }

    /**
     * The character starting at byte $offset: its whole UTF-8 sequence, or the
     * single byte there when that byte starts no valid sequence.
     */
    private function characterAt(int $offset): string
    {
        $lead = ord($this->source[$offset]);

        $length = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default => 1,
        };

        $character = substr($this->source, $offset, $length);

        return $this->isValidUtf8($character) ? $character : $this->source[$offset];
    }

    private function isValidUtf8(string $text): bool
    {
        return preg_match('//u', $text) === 1;
    }

    private function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    private function isIdentifierStart(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || $char === '_';
    }

    private function isIdentifierPart(string $char): bool
    {
        return $this->isIdentifierStart($char) || $this->isDigit($char) || $char === '-';
    }

    /**
     * Report a syntax error: throw it when reading strictly, and record it and
     * carry on when reading tolerantly. Every error the lexer finds comes through
     * here, so strict and tolerant reading agree on what one is.
     *
     * @param int $offset 0-based byte offset of the first byte the error covers.
     * @param int $line 1-based line of that byte, for the exception's message.
     * @param int $col 1-based byte column of that byte, for the exception's message.
     * @param int $endOffset Just past the last byte the error covers.
     */
    private function report(string $message, int $offset, int $line, int $col, int $endOffset): void
    {
        if (! $this->tolerant) {
            throw WarrantSyntaxException::atOffset($message, $this->source, $offset, $line, $col);
        }

        $this->diagnostics[] = new SyntaxDiagnostic($message, $offset, $endOffset);
    }

    /**
     * Report an error over the text from $offset to the current position, and
     * make that text an ERROR token.
     */
    private function errorToken(string $message, int $offset, int $line, int $col): Token
    {
        $this->report($message, $offset, $line, $col, $this->pos);

        return new Token(TokenType::ERROR, substr($this->source, $offset, $this->pos - $offset), $offset, $line, $col);
    }
}
