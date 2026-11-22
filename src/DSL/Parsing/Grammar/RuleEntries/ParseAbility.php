<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseBinding;
use Warrant\DSL\Parsing\Grammar\ParseConstantRef;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One ability: a name, `*` for every ability, a binding whose value names them,
 * or a constant of another schema, `<schema>.<name>`, whose value names them.
 *
 * A bare name stays a name: whether it is an ability or a constant naming
 * abilities is looked up where the schemas are known, not read from the text.
 *
 * @extends Parser<NameNode|BindingNode|ConstantRef>
 */
final class ParseAbility extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return in_array($ahead->peek()->type, [TokenType::STAR, TokenType::IDENTIFIER, TokenType::NAMED_BINDING], true);
    }

    protected function read(): NameNode|BindingNode|ConstantRef
    {
        if ($this->check(TokenType::NAMED_BINDING)) {
            return $this->parse(ParseBinding::class)->value;
        }

        if ($this->tokensAhead()->nextIs(TokenType::IDENTIFIER, TokenType::DOT)) {
            return $this->parse(ParseConstantRef::class)->value;
        }

        return new NameNode($this->advance()->lexeme, $this->locationEndingHere());
    }
}
