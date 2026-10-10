<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Grammar\GrammarParser;

/**
 * A comma-separated list of at least one ability, each a name or `*`, with
 * each noted as a part of the node around it, by its index.
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
        $missingAbility = fn () => $this->nameError('an ability name');

        return ($this->parseSeparatedList(ParseAbility::class, TokenType::COMMA, $missingAbility, $this->part)
            ?? throw $missingAbility())->value;
    }
}
