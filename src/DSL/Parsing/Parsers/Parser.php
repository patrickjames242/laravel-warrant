<?php

namespace Warrant\DSL\Parsing\Parsers;

use Closure;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * Reads one piece of the grammar from a {@see ParsingState}.
 *
 * A parser is read only where it starts ({@see startsHere()}), and the text
 * from there is its own: it returns what it read, of any type, null
 * included, and a mistake is an exception. Nothing else gets to try, so the
 * error is reported where the mistake is, and nothing read is ever put back.
 *
 * A tolerant parse, for an editor, notes each mistake instead of throwing it
 * ({@see report()}), and the parser that met it reads on as though the text
 * were what it should be, so what is around the mistake is kept.
 *
 * A parser builds each node with {@see located()} as its last argument, which
 * places it over every token the parser has read, and gives it every part read
 * inside it, by this parser or by one it called that returned something other
 * than a node. As the last argument, it is made once every parser read for the
 * node's other arguments has returned.
 *
 * A parser keeps nothing between reads but what it was constructed with, so
 * one instance can be read any number of times, and from inside itself.
 *
 * @template-covariant T
 */
abstract class Parser
{
    /**
     * The error for text after the end of what the whole parse reads.
     */
    final protected const UNEXPECTED_AFTER_END = 'Unexpected token; expected end of input.';

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
     * stands for the whole text, and no part may be read outside a node.
     *
     * @template R
     * @param Parser<R> $root
     * @return R
     */
    final public static function run(Parser $root, ParsingState $state): mixed
    {
        $root->state = $state;
        $state->openFrame();
        $result = $root->read();

        if (! $root->check(TokenType::EOF)) {
            $root->report($root->errorAtCurrent(DiagnosticCode::UnexpectedToken, self::UNEXPECTED_AFTER_END));
        }

        $state->closeFrame($result);

        return $result;
    }

    /**
     * Read $parser here, if it starts here: a class with no
     * constructor arguments, or an instance. It reads in a frame of its own, so
     * the nodes it builds are placed over what it read.
     *
     * @template R
     * @param class-string<Parser<R>>|Parser<R> $parser
     * @return Parsed<R>|null Null when $parser does not start here.
     */
    final protected function parse(string|Parser $parser): ?Parsed
    {
        if (! $parser::startsHere($this->state->tokensAhead)) {
            return null;
        }

        $parser = $this->share($parser);
        $this->state->openFrame();
        $result = $parser->read();
        $this->state->closeFrame($result);

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
     * @param class-string<Parser<R>>|Parser<R> $item What reads each item.
     * @param TokenType $separator The token between one item and the next.
     * @param Closure(): WarrantSyntaxException $missingAfterSeparator The error
     *   for an item missing after a separator, built only when one is.
     * @param string|null $part The part of the node around them each item is,
     *   noted by the item's index; null to note none.
     * @param (Closure(): R)|null $inPlaceOfMissingItem Makes what a tolerant parse
     *   puts in place of an item missing after a separator; null to put nothing.
     * @return list<R> Empty when no item starts here.
     */
    final protected function parseSeparatedList(
        string|Parser $item,
        TokenType $separator,
        Closure $missingAfterSeparator,
        ?string $part = null,
        ?Closure $inPlaceOfMissingItem = null,
    ): array {
        return $this->parse(
            new ParseSeparatedList($item, $separator, $missingAfterSeparator, $part, $inPlaceOfMissingItem),
        )->value;
    }

    // -- mistakes -------------------------------------------------------------

    /**
     * Report $error: thrown in a strict parse, which the first mistake ends, and
     * noted as a diagnostic in a tolerant one, which goes on.
     *
     * @param WarrantSyntaxException $error The mistake, with the message and the
     *   place in the text it is reported at.
     */
    final protected function report(WarrantSyntaxException $error): void
    {
        if (! $this->state->tolerant) {
            throw $error;
        }

        $this->state->diagnose($error);
    }

    /**
     * Skip tokens until $resumesHere says reading can go on, or the input ends.
     * A `{` skipped opens a block that is skipped whole, to its `}`, so a token
     * inside text being thrown away is not taken for one around it.
     *
     * @param Closure(TokensAhead): bool $resumesHere Says whether reading can go on
     *   at the tokens ahead: skipping stops at the first place it does.
     */
    final protected function skipTo(Closure $resumesHere): void
    {
        $depth = 0;

        while (! $this->check(TokenType::EOF)) {
            if ($depth === 0 && $resumesHere($this->state->tokensAhead)) {
                return;
            }

            $type = $this->advance()->type;

            if ($type === TokenType::LBRACE) {
                $depth++;
            } elseif ($type === TokenType::RBRACE && $depth > 0) {
                $depth--;
            }
        }
    }

    /**
     * Read a $type token, one the grammar needs here but whose text says nothing
     * more, such as a bracket or a keyword. When it is not here, a strict parse
     * throws $message, and a tolerant one notes it and goes on as though it were,
     * so what was read around it is kept.
     *
     * @param TokenType $type The token the grammar needs here, such as the `)`
     *   that closes an argument list.
     * @param string $message The error for $type not being here.
     */
    final protected function expectOrAssume(TokenType $type, string $message): void
    {
        if ($this->check($type)) {
            $this->advance();

            return;
        }

        $this->report($this->errorAtCurrent(DiagnosticCode::MissingToken, $message));
    }

    // -- placing --------------------------------------------------------------

    /**
     * Note that the text from $first to the last token read is $part of the next
     * node this parser, or the nearest one around it, builds.
     */
    final protected function part(string $part, int|string|null $key, Token $first): void
    {
        $this->state->recordPart($part, $key, $first, $this->previous());
    }

    /**
     * Where the node this parser is building was written: from where the parser
     * started, or from $start, to the last token read, with the parts read since
     * it started. $start is for a node that starts after the parser did: each
     * `and` but the first of `a and b and c` holds the one before it, and starts
     * where that one does.
     */
    final protected function located(?Token $start = null): SyntaxLocation
    {
        return $this->state->locate($start);
    }

    /**
     * Where a node that stands in for missing syntax is: where the parse is,
     * taking up no text.
     */
    final protected function locatedMissing(): SyntaxLocation
    {
        return $this->state->locateMissing();
    }

    /**
     * The index of the `?` placeholder being read, among the `?`s of the text.
     */
    final protected function nextPositionalIndex(): int
    {
        return $this->state->nextPositionalIndex();
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

    // -- errors ---------------------------------------------------------------

    /**
     * The error for a mistake at $token.
     *
     * @param DiagnosticCode $code Which kind of mistake it is.
     * @param string $message What is wrong, in words for the author.
     * @param Token $token The token the mistake is at.
     */
    final protected function errorAt(DiagnosticCode $code, string $message, Token $token): WarrantSyntaxException
    {
        return WarrantSyntaxException::at($code, $message, $this->state->text, $token);
    }

    /**
     * The error for a mistake at the current token.
     *
     * @param DiagnosticCode $code Which kind of mistake it is.
     * @param string $message What is wrong, in words for the author.
     */
    final protected function errorAtCurrent(DiagnosticCode $code, string $message): WarrantSyntaxException
    {
        return $this->errorAt($code, $message, $this->peek());
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
