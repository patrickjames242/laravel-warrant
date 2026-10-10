<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * A parenthesized argument list, `(arg, ...)`, with each argument noted as a
 * part of the node around it, by its index. Arguments are always written in
 * parentheses, so with no `(` here there is no list: {@see NOTHING}, which a
 * handle reads as selecting no rows and a condition as taking no arguments,
 * where `()` is a list that is empty.
 *
 * @extends GrammarParser<list<mixed>>
 */
final class ParseArguments extends GrammarParser
{
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

    /**
     * @return list<mixed>|NoMatch
     */
    protected function read(): array|NoMatch
    {
        if (! $this->check(TokenType::LPAREN)) {
            return self::NOTHING;
        }

        $this->advance();
        $arguments = [];

        if (! $this->check(TokenType::RPAREN)) {
            do {
                $first = $this->peek();
                $arguments[] = ($this->parse(ParseArgument::class) ?? throw $this->missingArgumentError())->value;
                $this->part($this->part, count($arguments) - 1, $first);
            } while ($this->check(TokenType::COMMA) && $this->advance());
        }

        $this->expect(TokenType::RPAREN, "Expected ')' to close {$this->closes}.");

        return $arguments;
    }
}
