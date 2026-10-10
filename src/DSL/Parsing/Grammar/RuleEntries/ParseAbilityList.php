<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Grammar\GrammarParser;

/**
 * A comma-separated list of abilities, each a name or `*`, with each noted as a
 * part of the node around it, by its index.
 *
 * @extends GrammarParser<list<string>>
 */
final class ParseAbilityList extends GrammarParser
{
    /**
     * @param string $part The part of the node around them the abilities are,
     *   as that node names it.
     */
    public function __construct(private readonly string $part)
    {
    }

    /**
     * @return list<string>
     */
    protected function read(): array
    {
        $abilities = [];

        do {
            $abilities[] = match (true) {
                $this->check(TokenType::STAR),
                $this->check(TokenType::IDENTIFIER) => $this->advance()->lexeme,
                default => throw $this->nameError('an ability name'),
            };
            $this->part($this->part, count($abilities) - 1, $this->previous());
        } while ($this->check(TokenType::COMMA) && $this->advance());

        return $abilities;
    }
}
