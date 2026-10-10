<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * One ability: a name, or `*` for every ability.
 *
 * @extends GrammarParser<string>
 */
final class ParseAbility extends GrammarParser
{
    protected function read(): string|NoMatch
    {
        if (! $this->check(TokenType::STAR) && ! $this->check(TokenType::IDENTIFIER)) {
            return self::NOTHING;
        }

        return $this->advance()->lexeme;
    }
}
