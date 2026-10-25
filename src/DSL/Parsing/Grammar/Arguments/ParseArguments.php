<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A parenthesized argument list, `(arg, ...)`. Arguments are always written in
 * parentheses, so with no `(` here there is no list: the list starts at `(`,
 * and a parse finds it not here, which a handle reads as selecting no rows and
 * a condition as taking no arguments, where `()` is a list that is empty.
 *
 * @extends Parser<list<\Warrant\DSL\Parsing\ASTNodes\INode>>
 */
final class ParseArguments extends Parser
{
    use ReportsMissingSyntax;

    /**
     * @param string $closes What the closing `)` closes, for the error when it
     *   is missing: "the condition arguments".
     */
    public function __construct(
        private readonly string $closes,
    ) {
    }

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::LPAREN);
    }

    /**
     * @return list<\Warrant\DSL\Parsing\ASTNodes\INode>
     */
    protected function read(): array
    {
        $this->advance(); // consume '('
        $missingArgument = fn () => $this->missingArgumentError();

        $arguments = [];

        if (! $this->check(TokenType::RPAREN)) {
            $arguments = $this->parseSeparatedList(
                ParseArgument::class,
                TokenType::COMMA,
                $missingArgument,
                fn () => new MissingArgumentNode($this->emptyLocationHere()),
            );

            if ($arguments === []) {
                $this->report($missingArgument());
                $arguments = [new MissingArgumentNode($this->emptyLocationHere())];
            }
        }

        $this->expectOrAssume(TokenType::RPAREN, "Expected ')' to close {$this->closes}.");

        return $arguments;
    }
}
