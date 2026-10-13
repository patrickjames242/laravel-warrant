<?php

namespace Warrant\DSL\Parsing\Parsers;

use LogicException;
use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * One parse of one text, shared by every {@see Parser} reading it: where the
 * parse is, and where each node and part it has read was written.
 *
 * Where things were written is kept as a log, and goes into a
 * {@see SourceMap} only once the parse is done.
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
     * Where the last mistake the parser reported is. A parser further out that
     * meets the same mistake at the same place reports it again, and it is
     * noted once.
     */
    private ?int $lastReportedOffset = null;

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
     * The owner of a part is null until the node it belongs to is returned.
     *
     * @var list<array{0: ?INode, 1: ?string, 2: int|string|null, 3: Token, 4: Token}>
     */
    private array $records = [];

    /** @var list<int> Where in $records each part waiting for a node is, oldest first. */
    private array $unclaimed = [];

    /**
     * Where in $records each node recorded whole is, by object id. A recorded
     * node is held in $records until the parse is done or its record is
     * forgotten, which takes its entry here with it, so its id names no other
     * object while it is here.
     *
     * @var array<int, int>
     */
    private array $recordedAt = [];

    /**
     * The tokens ahead of this state, as {@see Parser::startsHere()} is given
     * them, made once for every parser that asks.
     */
    public readonly TokensAhead $tokensAhead;

    /**
     * @param string $source The text being parsed, which errors quote and point
     *   into.
     * @param list<Token> $tokens The text's tokens, read in order by the parse,
     *   ending in the EOF token.
     * @param bool $tolerant Whether a syntax error is noted and read past, rather
     *   than thrown.
     */
    public function __construct(
        public readonly string $source,
        public readonly array $tokens,
        public readonly bool $tolerant = false,
    ) {
        $this->tokensAhead = new TokensAhead($this);
    }

    /**
     * The state for parsing $source.
     */
    public static function forSource(string $source): self
    {
        return new self($source, self::grammarTokens((new Lexer($source))->tokenize()));
    }

    /**
     * The state for analysing $source: every lexical error becomes a diagnostic,
     * and text the lexer could not read is left out of what the grammar reads.
     */
    public static function forAnalysis(string $source): self
    {
        $scanned = (new Lexer($source))->scan();
        $state = new self($source, self::grammarTokens($scanned->tokens), tolerant: true);
        $state->diagnostics = $scanned->diagnostics;
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
        if ($error->offset === $this->lastReportedOffset || isset($this->afterUnreadableText[$error->offset])) {
            return;
        }

        $this->lastReportedOffset = $error->offset;
        $endOffset = $error->offset;

        foreach ($this->tokens as $token) {
            if ($token->offset === $error->offset) {
                $endOffset = $token->endOffset();
                break;
            }
        }

        $this->diagnostics[] = new Diagnostic($error->reason, $error->offset, $endOffset);
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
     * How many records have been made: a mark that {@see forgetRecordsFrom()}
     * can cut the log back to.
     */
    public function recordCount(): int
    {
        return count($this->records);
    }

    /**
     * Forget every record made since there were $count, for nodes and parts read
     * by a parser that met a mistake and was read past: none of them is in the
     * tree, so none of them may reach the map.
     *
     * @param int $count How many records there were when the parser that met the
     *   mistake started, from {@see recordCount()}: every record after that many
     *   is forgotten, and every one before it kept.
     */
    public function forgetRecordsFrom(int $count): void
    {
        foreach (array_splice($this->records, $count) as [$node, $part]) {
            if ($part === null) {
                unset($this->recordedAt[spl_object_id($node)]);
            }
        }

        $this->unclaimed = array_values(array_filter(
            $this->unclaimed,
            static fn (int $position): bool => $position < $count,
        ));
    }

    /**
     * How many parts are waiting for a node: a parser starting now owns the
     * parts read after these.
     */
    public function unclaimedCount(): int
    {
        return count($this->unclaimed);
    }

    /**
     * Record that $node was written from $start to $end.
     */
    public function recordNode(INode $node, Token $start, Token $end): void
    {
        $this->recordedAt[spl_object_id($node)] = count($this->records);
        $this->records[] = [$node, null, null, $start, $end];
    }

    /**
     * Record that $first to $last is $part of a node not yet returned.
     */
    public function recordPart(string $part, int|string|null $key, Token $first, Token $last): void
    {
        $this->unclaimed[] = count($this->records);
        $this->records[] = [null, $part, $key, $first, $last];
    }

    /**
     * Record $node, returned by a parser that started when $unclaimedBefore
     * parts were waiting, as written from $start to $end, and give it every part
     * read since.
     *
     * A node already recorded was read by a parser further in and is being
     * handed back out, as a group hands back what is inside its parentheses. It
     * keeps the span it was read with, and a part read around it has no node to
     * belong to.
     */
    public function recordResult(INode $node, Token $start, Token $end, int $unclaimedBefore): void
    {
        if ($this->isRecorded($node)) {
            if (count($this->unclaimed) > $unclaimedBefore) {
                throw new LogicException(sprintf(
                    'Parts were read around a %s that was already recorded, so they belong to no node.',
                    get_debug_type($node),
                ));
            }

            return;
        }

        foreach (array_splice($this->unclaimed, $unclaimedBefore) as $position) {
            $this->records[$position][0] = $node;
        }

        $this->recordNode($node, $start, $end);
    }

    /**
     * Write every record into $map. Called once, when the parse is done.
     */
    public function commitTo(SourceMap $map): void
    {
        if ($this->unclaimed !== []) {
            throw new LogicException(sprintf(
                'Part [%s] was read outside any node.',
                $this->records[$this->unclaimed[0]][1],
            ));
        }

        foreach ($this->records as [$node, $part, $key, $first, $last]) {
            $part === null
                ? $map->record($node, $first, $last)
                : $map->recordPart($node, $part, $key, $first, $last);
        }
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

    private function isRecorded(INode $node): bool
    {
        return isset($this->recordedAt[spl_object_id($node)]);
    }
}
