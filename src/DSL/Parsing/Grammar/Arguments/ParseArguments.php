<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A parenthesized argument list, `(arg, ...)`, with each argument noted as a
 * part of the node around it, by its index. Arguments are always written in
 * parentheses, so with no `(` here there is no list: the list starts at `(`,
 * and a parse finds it not here, which a handle reads as selecting no rows and
 * a condition as taking no arguments, where `()` is a list that is empty.
 *
 * @extends Parser<list<mixed>>
 */
final class ParseArguments extends Parser
{
    use ReportsMissingSyntax;

    /**
     * @param string $part The part of the node around them the arguments are,
     *   as that node names it.
     * @param string $closes What the closing `)` closes, for the error when it
     *   is missing: "the condition arguments".
     */
    public function __construct(
        private readonly string $part,
        private readonly string $closes,
    ) {
    }

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::LPAREN);
    }

    /**
     * @return list<mixed>
     */
    protected function read(): array
    {
        $this->advance(); // consume '('
        $missingArgument = fn () => $this->missingArgumentError();

        $arguments = [];

        if (! $this->check(TokenType::RPAREN)) {
            $arguments = $this->parseSeparatedList(ParseArgument::class, TokenType::COMMA, $missingArgument, $this->part);

            if ($arguments === []) {
                throw $missingArgument();
            }
        }

        $this->expectOrAssume(TokenType::RPAREN, "Expected ')' to close {$this->closes}.");

        return $arguments;
    }
}
