<?php

namespace Warrant\DSL\Parsing\Parsers;

use LogicException;
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
 * to try, so the error is reported where the mistake is.
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

    private ParsingState $state;

    /**
     * @return T|NoMatch
     */
    abstract protected function read(): mixed;

    /**
     * Read $root as the whole text: nothing may follow what it reads, and every
     * binding must have been used. Its result stands for the whole text and is
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

        $root->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
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
