<?php

namespace Warrant\DSL\Parsing\Parsers;

use Closure;
use LogicException;
use Throwable;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\BindingState;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * Reads one piece of the grammar from a {@see ParsingState}.
 *
 * A parser returns what it read, of any type, null included, or
 * {@see NOTHING} when what it reads is not here. NOTHING puts back everything
 * the parser read, so it can look as far as it needs before deciding. Once it
 * has decided the text is its own, a mistake is an exception: nothing else gets
 * to try, so the error is reported where the mistake is. A mistake the parse
 * can read on past goes through {@see report()} instead, which throws it in a
 * strict read and notes it in an analysis.
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
    /**
     * Returned by {@see read()} when what this parser reads is not here.
     */
    final public const NOTHING = NoMatch::NoMatch;

    /**
     * Read by {@see parseOrSkip()} in place of text it stepped over.
     */
    final public const SKIPPED = Skipped::Skipped;

    private ParsingState $state;

    /**
     * @return T|NoMatch
     */
    abstract protected function read(): mixed;

    /**
     * Read $root as the whole text: nothing may follow what it reads, and every
     * binding must have been used. In an analysis, text that follows is
     * reported and left unread. Its result stands for the whole text and is
     * not recorded. A root reads something from any text, if only an error, so
     * {@see NOTHING} from it is a mistake in the parser.
     *
     * @template R
     * @param Parser<R> $root
     * @return R
     */
    final public static function run(Parser $root, ParsingState $state): mixed
    {
        $root->state = $state;
        $result = $root->read();

        if ($result === self::NOTHING) {
            throw new LogicException(sprintf('%s read nothing as the whole parse.', $root::class));
        }

        if (! $root->check(TokenType::EOF)) {
            $root->report($root->errorAtCurrent('Unexpected token; expected end of input.'));
        }

        $state->bindings->finalize($root->peek());

        return $result;
    }

    /**
     * Read $parser here: a class with no constructor arguments, or an instance.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $parser
     * @return Parsed<R>|null Null when $parser did not match.
     */
    final protected function parse(string|Parser $parser): ?Parsed
    {
        $parser = $this->share($parser);
        $start = $this->peek();
        $checkpoint = $this->state->checkpoint();

        $result = $parser->read();

        if ($result === self::NOTHING) {
            $this->state->restore($checkpoint);

            return null;
        }

        // A node read from no tokens has nowhere to be.
        if ($result instanceof INode && $this->state->index > $checkpoint->index) {
            $this->state->recordResult($result, $start, $this->previous(), $checkpoint);
        }

        return new Parsed($result);
    }

    /**
     * Read $parser and put back everything it read, whatever it returns: for a
     * question about the text ahead that the parse goes on to read for real.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $parser
     * @return Parsed<R>|null Null when $parser did not match.
     */
    final protected function lookahead(string|Parser $parser): ?Parsed
    {
        $parser = $this->share($parser);
        $checkpoint = $this->state->checkpoint();

        try {
            $result = $parser->read();
        } finally {
            $this->state->restore($checkpoint);
        }

        return $result === self::NOTHING ? null : new Parsed($result);
    }

    /**
     * Read the first of $parsers that matches here. See {@see ParseOneOf}.
     *
     * @param list<class-string<Parser>|Parser> $parsers In the order to try them.
     * @return Parsed<mixed>|null Null when none matched.
     */
    final protected function parseOneOf(array $parsers): ?Parsed
    {
        return $this->parse(new ParseOneOf($parsers));
    }

    /**
     * Read $item back to back for as long as one is here. See {@see ParseRepeated}.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $item
     * @return Parsed<list<R>>|null Null when not even one item is here.
     */
    final protected function parseRepeated(string|Parser $item): ?Parsed
    {
        return $this->parse(new ParseRepeated($item));
    }

    /**
     * Read $item with $separator between them. See {@see ParseSeparatedList}.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $item
     * @param Closure(): Throwable $missingAfterSeparator
     * @return Parsed<list<R>>|null Null when not even one item is here.
     */
    final protected function parseSeparatedList(
        string|Parser $item,
        TokenType $separator,
        Closure $missingAfterSeparator,
        ?string $part = null,
    ): ?Parsed {
        return $this->parse(new ParseSeparatedList($item, $separator, $missingAfterSeparator, $part));
    }

    /**
     * Read $parser here, as {@see parse()} does. In an analysis, an error it
     * throws is reported instead: everything it read is put back, and the text
     * from here is stepped over to where reading can start again, and read as
     * {@see SKIPPED}.
     *
     * The step goes at least one token, and at least to where $parser stopped,
     * so the same error is never met twice and every skip moves the parse on.
     * From there it goes on to the first token where $restartsHere is true
     * outside any braces opened along the way, since a brace opens a body of
     * its own whose tokens restart nothing out here, or to the end of the
     * input.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $parser
     * @param Closure(): bool $restartsHere Whether reading can start again at
     *   the current token.
     * @return Parsed<R|Skipped>|null Null when $parser did not match.
     */
    final protected function parseOrSkip(string|Parser $parser, Closure $restartsHere): ?Parsed
    {
        $checkpoint = $this->state->checkpoint();

        try {
            return $this->parse($parser);
        } catch (WarrantSyntaxException $error) {
            if (! $this->state->reportsErrors) {
                throw $error;
            }

            $stoppedAt = $this->state->index;
            $this->state->restore($checkpoint);
            $this->state->diagnose($error);
            $depth = 0;

            do {
                $depth += match ($this->peek()->type) {
                    TokenType::LBRACE => 1,
                    TokenType::RBRACE => -1,
                    default => 0,
                };
                $this->advance();
            } while (! $this->check(TokenType::EOF)
                && ($this->state->index < $stoppedAt || $depth > 0 || ! $restartsHere()));

            return new Parsed(self::SKIPPED);
        }
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
        return $this->state->tokens[min($this->state->index + $distance, count($this->state->tokens) - 1)];
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

    final protected function bindings(): BindingState
    {
        return $this->state->bindings;
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
     * Throw $error, or in an analysis note it and go on: for a mistake the
     * parse can read on past, where what follows reads the same either way.
     */
    final protected function report(WarrantSyntaxException $error): void
    {
        if (! $this->state->reportsErrors) {
            throw $error;
        }

        $this->state->diagnose($error);
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
