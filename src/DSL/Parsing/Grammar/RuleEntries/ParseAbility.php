<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * One ability: a name, or `*` for every ability.
 *
 * @extends Parser<string>
 */
final class ParseAbility extends Parser
{
    protected function read(): string|CouldNotParse
    {
        if (! $this->check(TokenType::STAR) && ! $this->check(TokenType::IDENTIFIER)) {
            return self::COULD_NOT_PARSE;
        }

        return $this->advance()->lexeme;
    }
}
