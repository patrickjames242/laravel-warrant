<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One literal: a quoted string, a number, `true`, `false` or `null`.
 *
 * @extends Parser<LiteralNode>
 */
final class ParseLiteral extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return in_array($ahead->peek()->type, [
            TokenType::STRING,
            TokenType::INT,
            TokenType::FLOAT,
            TokenType::BOOL,
            TokenType::NULL,
        ], true);
    }

    protected function read(): LiteralNode
    {
        return new LiteralNode($this->advance()->value, $this->locationEndingHere());
    }
}
