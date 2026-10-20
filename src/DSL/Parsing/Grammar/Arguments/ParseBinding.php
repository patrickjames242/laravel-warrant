<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One binding: `:name`. Its value is not read here: the binding finds it in
 * the source it was read from.
 *
 * @extends Parser<BindingNode>
 */
final class ParseBinding extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->peek()->type === TokenType::NAMED_BINDING;
    }

    protected function read(): BindingNode
    {
        return new BindingNode($this->advance()->value, $this->locationEndingHere());
    }
}
