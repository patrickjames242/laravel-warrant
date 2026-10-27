<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseBinding;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One ability after `* except`: a name, or a binding whose value names one or
 * more.
 *
 * @extends Parser<NameNode|BindingNode>
 */
final class ParseExceptedAbility extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return in_array($ahead->peek()->type, [TokenType::IDENTIFIER, TokenType::NAMED_BINDING], true);
    }

    protected function read(): NameNode|BindingNode
    {
        if ($this->check(TokenType::NAMED_BINDING)) {
            return $this->parse(ParseBinding::class)->value;
        }

        return new NameNode($this->advance()->lexeme, $this->locationEndingHere());
    }
}
