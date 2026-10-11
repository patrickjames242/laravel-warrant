<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * One ability: a name, or `*` for every ability.
 *
 * @extends Parser<string>
 */
final class ParseAbility extends Parser
{
    protected function read(): string|NoMatch
    {
        if (! $this->check(TokenType::STAR) && ! $this->check(TokenType::IDENTIFIER)) {
            return self::NOTHING;
        }

        return $this->advance()->lexeme;
    }
}
