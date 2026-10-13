<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * One ability: a name, or `*` for every ability.
 *
 * @extends Parser<string>
 */
final class ParseAbility extends Parser
{
    protected static function startsWith(): array
    {
        return [TokenType::STAR, TokenType::IDENTIFIER];
    }

    protected function read(): string
    {
        return $this->advance()->lexeme;
    }
}
