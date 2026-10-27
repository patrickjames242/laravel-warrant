<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\EveryAbilityExceptNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseBinding;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One ability: a name, `*` for every ability, `* except` and the abilities it
 * leaves out, or a binding whose value names them. The abilities after
 * `except` run to the end of the list, since nothing may stand beside `*`.
 *
 * @extends Parser<NameNode|BindingNode|EveryAbilityExceptNode>
 */
final class ParseAbility extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return in_array($ahead->peek()->type, [TokenType::STAR, TokenType::IDENTIFIER, TokenType::NAMED_BINDING], true);
    }

    protected function read(): NameNode|BindingNode|EveryAbilityExceptNode
    {
        if ($this->check(TokenType::NAMED_BINDING)) {
            return $this->parse(ParseBinding::class)->value;
        }

        $name = new NameNode($this->advance()->lexeme, $this->locationEndingHere());

        if ($name->name !== '*' || ! $this->check(TokenType::EXCEPT)) {
            return $name;
        }

        $this->advance(); // consume 'except'

        $missingAbility = fn () => $this->nameError('an ability name');
        $exceptions = $this->parseSeparatedList(ParseExceptedAbility::class, TokenType::COMMA, $missingAbility);

        if ($exceptions === []) {
            $this->report($missingAbility());
        }

        return new EveryAbilityExceptNode($name, $exceptions, $this->locationEndingHere());
    }
}
