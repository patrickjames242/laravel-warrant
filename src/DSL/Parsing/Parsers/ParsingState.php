<?php

namespace Warrant\DSL\Parsing\Parsers;

use LogicException;
use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\BindingState;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\DSL\Parsing\SyntaxDiagnostic;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * One parse of one text, shared by every {@see Parser} reading it: where the
 * parse is, the bindings it resolves, and where each node and part it has read
 * was written.
 *
 * Where things were written is kept as a log, and goes into a
 * {@see SourceMap} only once the parse is done. A parser that does not match
 * has its records cut off the end of the log, so nothing it read reaches the
 * map.
 *
 * A state for analysis, made by {@see forAnalysis()}, reads text as an editor
 * has it: the lexer reports its errors instead of throwing them, so does the
 * grammar where it can read on past one, and the placeholders have no values.
 * A parser that does not match has its diagnostics cut off too.
 */
final class ParsingState
{
    /**
     * The most diagnostics an analysis notes. Past this many, text is so far
     * from the grammar that more say nothing new.
     */
    public const MAX_DIAGNOSTICS = 100;

    public int $index = 0;

    /** @var list<SyntaxDiagnostic> Every syntax error found so far, for analysis. */
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
     * Where in $records each node recorded whole is, by object id. An entry left
     * behind by a rewind is told apart by the record at its position no longer
     * being that node.
     *
     * @var array<int, int>
     */
    private array $recordedAt = [];

    /**
     * @param list<Token> $tokens Ending in the EOF token.
     * @param bool $reportsErrors Whether an error the parse can read on past is
     *   noted as a diagnostic rather than thrown.
     */
    public function __construct(
        public readonly string $source,
        public readonly array $tokens,
        public BindingState $bindings,
        public readonly bool $reportsErrors = false,
    ) {
    }

    /**
     * The state for parsing $source, with $bindings to resolve its placeholders.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function forSource(string $source, array $bindings = []): self
    {
        return new self($source, self::withoutComments((new Lexer($source))->tokenize()), new BindingState($source, $bindings));
    }

    /**
     * The state for analysing $source: every lexical error becomes a diagnostic
     * and text the lexer could not read an ERROR token, and the placeholders
     * stand for values that are not known.
     */
    public static function forAnalysis(string $source): self
    {
        $scanned = (new Lexer($source))->scan();
        $state = new self(
            $source,
            self::withoutComments($scanned->tokens),
            BindingState::placeholdersAsTheirOwnText($source),
            reportsErrors: true,
        );
        $state->diagnostics = $scanned->diagnostics;

        return $state;
    }

    /**
     * Note $error as a diagnostic, over the token it was raised at, unless an
     * error is already noted there. The first error at a place is the one that
     * says what is wrong with it, and the rest follow from it, as a parser
     * reading an ERROR token follows from the lexer failing to read the text.
     */
    public function diagnose(WarrantSyntaxException $error): void
    {
        if (count($this->diagnostics) >= self::MAX_DIAGNOSTICS) {
            return;
        }

        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->offset === $error->offset) {
                return;
            }
        }

        $endOffset = $error->offset;

        foreach ($this->tokens as $token) {
            if ($token->offset === $error->offset) {
                $endOffset = $token->endOffset();
                break;
            }
        }

        $this->diagnostics[] = new SyntaxDiagnostic($error->reason, $error->offset, $endOffset);
    }

    public function checkpoint(): Checkpoint
    {
        return new Checkpoint(
            $this->index,
            clone $this->bindings,
            count($this->records),
            count($this->unclaimed),
            count($this->diagnostics),
        );
    }

    /**
     * Put the state back as it was at $checkpoint. The bindings are copied
     * again, so one checkpoint can be restored any number of times.
     */
    public function restore(Checkpoint $checkpoint): void
    {
        $this->index = $checkpoint->index;
        $this->bindings = clone $checkpoint->bindings;
        array_splice($this->records, $checkpoint->records);
        array_splice($this->unclaimed, $checkpoint->unclaimed);
        array_splice($this->diagnostics, $checkpoint->diagnostics);
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
     * Record $node, returned by a parser that started at $checkpoint, as written
     * from $start to $end, and give it every part read since.
     *
     * A node already recorded was read by a parser further in and is being
     * handed back out, as a group hands back what is inside its parentheses. It
     * keeps the span it was read with, and a part read around it has no node to
     * belong to.
     */
    public function recordResult(INode $node, Token $start, Token $end, Checkpoint $checkpoint): void
    {
        if ($this->isRecorded($node)) {
            if (count($this->unclaimed) > $checkpoint->unclaimed) {
                throw new LogicException(sprintf(
                    'Parts were read around a %s that was already recorded, so they belong to no node.',
                    get_debug_type($node),
                ));
            }

            return;
        }

        foreach (array_splice($this->unclaimed, $checkpoint->unclaimed) as $position) {
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
        $position = $this->recordedAt[spl_object_id($node)] ?? null;

        return $position !== null
            && ($this->records[$position][0] ?? null) === $node
            && $this->records[$position][1] === null;
    }
}
