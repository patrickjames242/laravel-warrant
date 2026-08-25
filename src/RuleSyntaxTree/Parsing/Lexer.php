<?php

namespace Warrant\RuleSyntaxTree\Parsing;

use Warrant\RuleSyntaxTree\WarrantSyntaxException;

/**
 * Turns raw Warrant syntax into a flat list of {@see Token}s.
 *
 * Whitespace (including newlines) is insignificant and simply separates tokens.
 * A `#` begins a line comment that runs to the end of the line (or the end of
 * the source); comments are trivia and never reach the parser. A `#` inside a
 * string literal is literal, since comments are only recognised between tokens.
 * Keywords are matched case-sensitively in lower case: `if`, `they`, `can`,
 * `cannot`, `because`, `check`, `and`, `or`, `not`, `for`, `with`. `true` / `false` / `null` are
 * always lexed as literals, so they cannot double as condition or ability names.
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
    ];

    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;
    private readonly int $length;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @return list<Token>
     */
    public function tokenize(): array
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
            $char === '!' => $this->single(TokenType::NOT),
            $char === '?' => $this->single(TokenType::POSITIONAL),
            $char === ':' => $this->scanNamedBinding(),
            $char === '@' => $this->scanContextRef(),
            $char === "'" => $this->scanString(),
            $this->isDigit($char) => $this->scanNumber(),
            $char === '-' => $this->scanNumber(),
            $this->isIdentifierStart($char) => $this->scanWord(),
            default => throw $this->error(sprintf('Unexpected character %s.', var_export($char, true))),
        };
    }

    private function scanNamedBinding(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $this->advance(); // consume ':'

        if ($this->pos >= $this->length || ! $this->isIdentifierStart($this->source[$this->pos])) {
            throw $this->error("Expected a binding name after ':'.");
        }

        $name = $this->consumeIdentifier();

        return new Token(TokenType::NAMED_BINDING, ':' . $name, $startOffset, $startLine, $startCol, $name);
    }

    private function scanContextRef(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $this->advance(); // consume '@'

        if ($this->pos >= $this->length || ! $this->isIdentifierStart($this->source[$this->pos])) {
            throw $this->errorAt("Expected 'context' after '@'.", $startOffset, $startLine, $startCol);
        }

        $word = $this->consumeIdentifier();

        if ($word !== 'context') {
            throw $this->errorAt(
                sprintf("Expected 'context' after '@', got '%s'.", $word),
                $startOffset,
                $startLine,
                $startCol,
            );
        }

        return new Token(TokenType::CONTEXT_REF, '@context', $startOffset, $startLine, $startCol);
    }

    private function scanString(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        $this->advance(); // consume opening quote

        $value = '';

        while (true) {
            if ($this->pos >= $this->length) {
                throw $this->errorAt('Unterminated string literal.', $startOffset, $startLine, $startCol);
            }

            $char = $this->source[$this->pos];

            if ($char === '\\') {
                $this->advance();

                if ($this->pos >= $this->length) {
                    throw $this->errorAt('Unterminated string literal.', $startOffset, $startLine, $startCol);
                }

                $escaped = $this->source[$this->pos];

                $value .= match ($escaped) {
                    "'" => "'",
                    '\\' => '\\',
                    default => throw $this->error(sprintf('Invalid escape sequence "\\%s"; only \\\' and \\\\ are allowed.', $escaped)),
                };

                $this->advance();
                continue;
            }

            if ($char === "'") {
                $this->advance(); // consume closing quote
                $lexeme = substr($this->source, $startOffset, $this->pos - $startOffset);

                return new Token(TokenType::STRING, $lexeme, $startOffset, $startLine, $startCol, $value);
            }

            $value .= $char;
            $this->advance();
        }
    }

    private function scanNumber(): Token
    {
        $startOffset = $this->pos;
        $startLine = $this->line;
        $startCol = $this->col;

        if ($this->source[$this->pos] === '-') {
            $this->advance();

            if ($this->pos >= $this->length || ! $this->isDigit($this->source[$this->pos])) {
                throw $this->errorAt('Expected a digit after "-".', $startOffset, $startLine, $startCol);
            }
        }

        while ($this->pos < $this->length && $this->isDigit($this->source[$this->pos])) {
            $this->advance();
        }

        $isFloat = false;

        if ($this->pos < $this->length && $this->source[$this->pos] === '.') {
            $isFloat = true;
            $this->advance();

            if ($this->pos >= $this->length || ! $this->isDigit($this->source[$this->pos])) {
                throw $this->errorAt('Expected a digit after the decimal point.', $startOffset, $startLine, $startCol);
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

    private function error(string $message): WarrantSyntaxException
    {
        return $this->errorAt($message, $this->pos, $this->line, $this->col);
    }

    private function errorAt(string $message, int $offset, int $line, int $col): WarrantSyntaxException
    {
        return WarrantSyntaxException::atOffset($message, $this->source, $offset, $line, $col);
    }
}
