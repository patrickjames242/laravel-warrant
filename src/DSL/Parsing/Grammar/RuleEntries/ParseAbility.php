<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseBinding;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One ability: a name, `*` for every ability, or a binding whose value names
 * them.
 *
 * @extends Parser<NameNode|BindingNode>
 */
final class ParseAbility extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return in_array($ahead->peek()->type, [TokenType::STAR, TokenType::IDENTIFIER, TokenType::NAMED_BINDING], true);
    }

    protected function read(): NameNode|BindingNode
    {
        if ($this->check(TokenType::NAMED_BINDING)) {
            return $this->parse(ParseBinding::class)->value;
        }

        return new NameNode($this->advance()->lexeme, $this->locationEndingHere());
    }
}
