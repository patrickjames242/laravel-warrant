<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One name the text writes: a condition, template or schema key, an alias, a
 * column or a context key.
 *
 * @extends Parser<NameNode>
 */
final class ParseName extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::IDENTIFIER);
    }

    protected function read(): NameNode
    {
        return new NameNode($this->advance()->lexeme, $this->locationEndingHere());
    }
}
