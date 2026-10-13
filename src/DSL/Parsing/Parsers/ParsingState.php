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
 * has it: the lexer reports its errors instead of throwing them.
 */
final class ParsingState
{
    public int $index = 0;

    /** @var list<Diagnostic> Every syntax error found so far, for analysis. */
    public array $diagnostics = [];

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
     * node is held in $records until the parse is done, so its id names no other
     * object until then.
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
     * @param list<Token> $tokens Ending in the EOF token.
     */
    public function __construct(
        public readonly string $source,
        public readonly array $tokens,
    ) {
        $this->tokensAhead = new TokensAhead($this);
    }

    /**
     * The state for parsing $source.
     */
    public static function forSource(string $source): self
    {
        return new self($source, self::withoutComments((new Lexer($source))->tokenize()));
    }

    /**
     * The state for analysing $source: every lexical error becomes a diagnostic
     * and text the lexer could not read an ERROR token.
     */
    public static function forAnalysis(string $source): self
    {
        $scanned = (new Lexer($source))->scan();
        $state = new self($source, self::withoutComments($scanned->tokens));
        $state->diagnostics = $scanned->diagnostics;

        return $state;
    }

    /**
     * Note $error as a diagnostic, over the token it was raised at.
     */
    public function diagnose(WarrantSyntaxException $error): void
    {
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
     * Comments say nothing about what a rule means, so the grammar never sees them.
     *
     * @param list<Token> $tokens
     * @return list<Token>
     */
    private static function withoutComments(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (Token $token): bool => $token->type !== TokenType::COMMENT,
        ));
    }

    private function isRecorded(INode $node): bool
    {
        return isset($this->recordedAt[spl_object_id($node)]);
    }
}
