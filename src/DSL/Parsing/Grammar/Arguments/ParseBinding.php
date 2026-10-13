<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One binding: `:name` or `?`. Its value is not read here; it is put in place
 * by {@see \Warrant\DSL\Parsing\BindingResolver} once the whole text is read.
 *
 * @extends Parser<BindingNode>
 */
final class ParseBinding extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return in_array($ahead->peek()->type, [TokenType::NAMED_BINDING, TokenType::POSITIONAL], true);
    }

    protected function read(): BindingNode
    {
        if ($this->check(TokenType::NAMED_BINDING)) {
            return new BindingNode($this->advance()->value);
        }

        $this->advance(); // consume '?'

        return new BindingNode;
    }
}
