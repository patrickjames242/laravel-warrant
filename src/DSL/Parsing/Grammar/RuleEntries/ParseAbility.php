<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ReadonlyParsingState;

/**
 * One ability: a name, or `*` for every ability.
 *
 * @extends Parser<string>
 */
final class ParseAbility extends Parser
{
    protected static function startsHere(ReadonlyParsingState $state): bool
    {
        return in_array($state->peek()->type, [TokenType::STAR, TokenType::IDENTIFIER], true);
    }

    protected function read(): string
    {
        return $this->advance()->lexeme;
    }
}
