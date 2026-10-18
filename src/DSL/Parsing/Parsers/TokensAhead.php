<?php

namespace Warrant\DSL\Parsing\Parsers;

use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use WeakReference;

/**
 * The tokens ahead of where a {@see ParsingState} is: they can be looked at, but
 * the parse cannot be moved through them and no part can be noted. They are
 * what {@see Parser::startsHere()} is given, so deciding whether a parser starts
 * here cannot read anything.
 *
 * The state is held weakly. The state holds this, so a strong reference back
 * would make a cycle, and every parse's state, tokens and parts would then
 * outlive it until the cycle collector ran.
 */
final class TokensAhead
{
    /** @var WeakReference<ParsingState> */
    private readonly WeakReference $state;

    public function __construct(ParsingState $state)
    {
        $this->state = WeakReference::create($state);
    }

    /**
     * The token the parse is at.
     */
    public function peek(): Token
    {
        return $this->state->get()->peek();
    }

    /**
     * The token $distance places past the current one, clamped to the EOF token
     * that always terminates the stream.
     */
    public function peekAhead(int $distance = 1): Token
    {
        return $this->state->get()->peekAhead($distance);
    }

    /**
     * Whether the tokens ahead are $types, in order: the current token is the
     * first, the one after it the second, and so on.
     */
    public function nextIs(TokenType $type, TokenType ...$following): bool
    {
        $state = $this->state->get();

        if ($state->peek()->type !== $type) {
            return false;
        }

        foreach ($following as $index => $next) {
            if ($state->peekAhead($index + 1)->type !== $next) {
                return false;
            }
        }

        return true;
    }
}
