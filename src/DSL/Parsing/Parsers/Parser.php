<?php

namespace Warrant\DSL\Parsing\Parsers;

use Closure;
use Throwable;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * Reads one piece of the grammar from a {@see ParsingState}.
 *
 * A parser is read only where it starts ({@see startsHere()}), and the text
 * from there is its own: it returns what it read, of any type, null
 * included, and a mistake is an exception. Nothing else gets to try, so the
 * error is reported where the mistake is, and nothing read is ever put back.
 *
 * A node a parser returns is recorded as written over every token the parser
 * read, and every part read inside it, by this parser or by one it called that
 * returned something other than a node, becomes a part of that node.
 *
 * A parser keeps nothing between reads but what it was constructed with, so
 * one instance can be read any number of times, and from inside itself.
 *
 * @template-covariant T
 */
abstract class Parser
{
    private ParsingState $state;

    /**
     * @return T
     */
    abstract protected function read(): mixed;

    /**
     * Whether a parser of this class starts at the tokens $ahead. True
     * unless a class says otherwise: one that cannot say may start anywhere, as
     * may one whose start depends on what an instance is constructed with.
     *
     * A parser is not read where it does not start: {@see parse()} answers that
     * it is not here without making it. So {@see read()} need not check what it
     * starts with, and this holds wherever read() can match: a start it misses
     * turns text the parser reads into a parse error.
     */
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return true;
    }

    /**
     * Read $root as the whole text: nothing may follow what it reads. Its result
     * stands for the whole text and is not recorded.
     *
     * @template R
     * @param Parser<R> $root
     * @return R
     */
    final public static function run(Parser $root, ParsingState $state): mixed
    {
        $root->state = $state;
        $result = $root->read();

        $root->expect(TokenType::EOF, 'Unexpected token; expected end of input.');

        return $result;
    }

    /**
     * Read $parser here, if it starts here: a class with no
     * constructor arguments, or an instance.
     *
     * A node $parser returns is recorded as written over what it read, unless
     * $record is false: for a parser that reads a part of the node being built
     * rather than a node, and records that part itself, whose value may be a
     * node already recorded where it was read — a binding as a denial message.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $parser
     * @return Parsed<R>|null Null when $parser does not start here.
     */
    final protected function parse(string|Parser $parser, bool $record = true): ?Parsed
    {
        if (! $parser::startsHere($this->state->tokensAhead)) {
            return null;
        }

        $parser = $this->share($parser);
        $start = $this->peek();
        $startIndex = $this->state->index;
        $unclaimedBefore = $this->state->unclaimedCount();

        $result = $parser->read();

        // A node read from no tokens has nowhere to be.
        if ($record && $result instanceof INode && $this->state->index > $startIndex) {
            $this->state->recordResult($result, $start, $this->previous(), $unclaimedBefore);
        }

        return new Parsed($result);
    }

    /**
     * Read $item back to back for as long as one starts here. See {@see ParseRepeated}.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $item
     * @return list<R> Empty when no item starts here.
     */
    final protected function parseRepeated(string|Parser $item): array
    {
        return $this->parse(new ParseRepeated($item))->value;
    }

    /**
     * Read $item with $separator between them. See {@see ParseSeparatedList}.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $item
     * @param Closure(): Throwable $missingAfterSeparator
     * @return list<R> Empty when no item starts here.
     */
    final protected function parseSeparatedList(
        string|Parser $item,
        TokenType $separator,
        Closure $missingAfterSeparator,
        ?string $part = null,
    ): array {
        return $this->parse(new ParseSeparatedList($item, $separator, $missingAfterSeparator, $part))->value;
    }

    // -- recording ------------------------------------------------------------

    /**
     * Note that the text from $first to the last token read is $part of the node
     * this parser, or the nearest one around it, returns.
     */
    final protected function part(string $part, int|string|null $key, Token $first): void
    {
        $this->state->recordPart($part, $key, $first, $this->previous());
    }

    /**
     * Record $node as written from $start to the last token read, for a node
     * this parser builds but does not itself return: each `and` but the last of
     * `a and b and c`, which the next one holds.
     *
     * @template N of INode
     * @param N $node
     * @return N
     */
    final protected function node(INode $node, Token $start): INode
    {
        $this->state->recordNode($node, $start, $this->previous());

        return $node;
    }

    // -- tokens ---------------------------------------------------------------

    /**
     * The tokens ahead, as {@see startsHere()} is given them, for a parser that
     * asks whether another starts here without reading it.
     */
    final protected function tokensAhead(): TokensAhead
    {
        return $this->state->tokensAhead;
    }

    final protected function peek(): Token
    {
        return $this->state->tokens[$this->state->index];
    }

    /**
     * The token $distance places past the current one, clamped to the EOF token
     * that always terminates the stream.
     */
    final protected function peekAhead(int $distance = 1): Token
    {
        return $this->state->peekAhead($distance);
    }

    /**
     * The last token read.
     */
    final protected function previous(): Token
    {
        return $this->state->tokens[$this->state->index - 1];
    }

    final protected function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    final protected function advance(): Token
    {
        $token = $this->peek();

        if ($token->type !== TokenType::EOF) {
            $this->state->index++;
        }

        return $token;
    }

    final protected function expect(TokenType $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        throw $this->errorAtCurrent($message);
    }

    // -- errors ---------------------------------------------------------------

    final protected function errorAt(string $message, Token $token): WarrantSyntaxException
    {
        return WarrantSyntaxException::at($message, $this->state->source, $token);
    }

    final protected function errorAtCurrent(string $message): WarrantSyntaxException
    {
        return $this->errorAt($message, $this->peek());
    }

    /**
     * $parser, made ready to read from this parser's state.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $parser
     * @return Parser<R>
     */
    private function share(string|Parser $parser): Parser
    {
        $parser = is_string($parser) ? new $parser : $parser;
        $parser->state = $this->state;

        return $parser;
    }
}
