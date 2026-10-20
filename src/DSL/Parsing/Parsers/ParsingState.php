<?php

namespace Warrant\DSL\Parsing\Parsers;

use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\Positions\Span;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * One parse of one text, shared by every {@see Parser} reading it: where the
 * parse is, and the source every node it reads is placed in.
 *
 * Where each parser reading started is marked, so a node can be placed when it
 * is built: over the tokens its parser has read from there up to that moment.
 *
 * A state for analysis, made by {@see forAnalysis()}, reads text as an editor
 * has it, tolerantly: the lexer reports its errors instead of throwing them, and
 * so does the parser, which goes on past each mistake to read what follows.
 */
final class ParsingState
{
    public int $index = 0;

    /** @var list<Diagnostic> Every syntax error found so far, for analysis. */
    public array $diagnostics = [];

    /**
     * @var list<Token> Every token the lexer read, in order, for analysis: the
     *   ones the grammar reads, and the comments and unreadable text it does not.
     *   Empty in a strict parse, which keeps only what the grammar reads.
     */
    public array $scannedTokens = [];

    /**
     * The token the parse was at when it last noted a mistake. One mistake leads
     * to others close after it: a parser further out meets it again at the same
     * place, and reading on past a repair can trip over the text the repair
     * guessed wrong about. So, as Bison does, no mistake is noted until
     * {@see TOKENS_BETWEEN_MISTAKES} tokens have been read since the last.
     */
    private ?int $lastReportedAt = null;

    /**
     * How many tokens are read after a mistake is noted before another can be.
     */
    private const TOKENS_BETWEEN_MISTAKES = 3;

    /**
     * Where each token right after text the lexer could not read starts. The
     * grammar does not see that text, so a token it needed in its place is
     * missed at the token after it; that mistake is the unreadable text's, which
     * the lexer has reported, and is not noted again.
     *
     * @var array<int, true>
     */
    private array $afterUnreadableText = [];

    /**
     * The index of the token each parser reading started at, outermost first.
     *
     * @var list<int>
     */
    private array $starts = [];

    /** The text being parsed, which errors quote and point into. */
    public readonly string $text;

    /**
     * The tokens ahead of this state, as {@see Parser::startsHere()} is given
     * them, made once for every parser that asks.
     */
    public readonly TokensAhead $tokensAhead;

    /**
     * @param SyntaxSource $source Where the text came from, which every node
     *   read from it is placed in.
     * @param list<Token> $tokens The text's tokens, read in order by the parse,
     *   ending in the EOF token.
     * @param bool $tolerant Whether a syntax error is noted and read past, rather
     *   than thrown.
     */
    public function __construct(
        public readonly SyntaxSource $source,
        public readonly array $tokens,
        public readonly bool $tolerant = false,
    ) {
        $this->text = $source->bound->syntax ?? '';
        $this->tokensAhead = new TokensAhead($this);
    }

    /**
     * The state for parsing the text of $source.
     */
    public static function forSource(SyntaxSource $source): self
    {
        return new self($source, self::grammarTokens((new Lexer($source->bound->syntax ?? ''))->tokenize()));
    }

    /**
     * The state for analysing the text of $source: every lexical error becomes
     * a diagnostic, and text the lexer could not read is left out of what the
     * grammar reads.
     */
    public static function forAnalysis(SyntaxSource $source): self
    {
        $scanned = (new Lexer($source->bound->syntax ?? ''))->scan();
        $state = new self($source, self::grammarTokens($scanned->tokens), tolerant: true);
        $state->diagnostics = $scanned->diagnostics;
        $state->scannedTokens = $scanned->tokens;
        $state->afterUnreadableText = self::offsetsAfterUnreadableText($scanned->tokens);

        return $state;
    }

    /**
     * Note $error as a diagnostic, over the token it was raised at.
     *
     * @param WarrantSyntaxException $error The mistake to note: its message
     *   becomes the diagnostic's, and its offset where the diagnostic starts.
     */
    public function diagnose(WarrantSyntaxException $error): void
    {
        if (isset($this->afterUnreadableText[$error->offset])) {
            return;
        }

        if ($this->lastReportedAt !== null && $this->index - $this->lastReportedAt < self::TOKENS_BETWEEN_MISTAKES) {
            return;
        }

        $this->lastReportedAt = $this->index;
        $endOffset = $error->offset;

        foreach ($this->tokens as $token) {
            if ($token->offset === $error->offset) {
                $endOffset = $token->endOffset();
                break;
            }
        }

        $this->diagnostics[] = new Diagnostic($error->reason, $error->offset, $endOffset, $error->diagnosticCode);
    }

    /**
     * The token the parse is at.
     */
    public function peek(): Token
    {
        return $this->tokens[$this->index];
    }

    /**
     * The token $distance places past the current one, clamped to the EOF token
     * that always terminates the stream.
     */
    public function peekAhead(int $distance = 1): Token
    {
        return $this->tokens[min($this->index + $distance, count($this->tokens) - 1)];
    }

    /**
     * Mark where the parser about to read starts.
     */
    public function markStart(): void
    {
        $this->starts[] = $this->index;
    }

    /**
     * Forget where the parser that has just read started.
     */
    public function dropStart(): void
    {
        array_pop($this->starts);
    }

    /**
     * Where the node the current parser is building was written: from $start,
     * or where the parser started, to the last token read so far. A node read
     * from no tokens is placed where the parse is, taking up no text.
     */
    public function locationEndingHere(?Token $start = null): SyntaxLocation
    {
        $startIndex = $this->starts[array_key_last($this->starts)];

        if ($start === null && $this->index === $startIndex) {
            return $this->emptyLocationHere();
        }

        return SyntaxLocation::in(
            $this->source,
            Span::between($start ?? $this->tokens[$startIndex], $this->tokens[$this->index - 1]),
        );
    }

    /**
     * Where the parse is, taking up no text: the place of a node that stands in
     * for missing syntax.
     */
    public function emptyLocationHere(): SyntaxLocation
    {
        $offset = $this->peek()->offset;

        return SyntaxLocation::in($this->source, new Span($offset, $offset));
    }

    /**
     * $tokens as the grammar reads them. Comments say nothing about what a rule
     * means, and text the lexer could not read is reported by the lexer, so the
     * grammar sees neither; read on, the text around unreadable text reads as
     * though it were not there. A strict read has no ERROR tokens, since the lexer
     * throws at the first such text.
     *
     * @param list<Token> $tokens Every token the lexer read, in order.
     * @return list<Token>
     */
    private static function grammarTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (Token $token): bool => $token->type !== TokenType::COMMENT && $token->type !== TokenType::ERROR,
        ));
    }

    /**
     * Where each token that follows text the lexer could not read starts, as
     * {@see $afterUnreadableText} keeps them. A comment between the two is passed
     * over, since the grammar does not see it either.
     *
     * @param list<Token> $tokens Every token the lexer read, in order.
     * @return array<int, true>
     */
    private static function offsetsAfterUnreadableText(array $tokens): array
    {
        $offsets = [];
        $afterUnreadable = false;

        foreach ($tokens as $token) {
            if ($token->type === TokenType::ERROR) {
                $afterUnreadable = true;
            } elseif ($token->type !== TokenType::COMMENT) {
                if ($afterUnreadable) {
                    $offsets[$token->offset] = true;
                }

                $afterUnreadable = false;
            }
        }

        return $offsets;
    }
}
