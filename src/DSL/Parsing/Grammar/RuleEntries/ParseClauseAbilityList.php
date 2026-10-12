<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The abilities one `they can` / `they cannot` clause, or an ability block's
 * header, names after its keyword: its list, or none when the clause ends right
 * there — at the next entry, a `because`, a `for`, a brace or the end.
 *
 * Whether a clause may name its abilities depends on where it is written — not
 * inside an ability block, whose header names them; always in a `for` body —
 * and that is the validator's to report. A name after `can` / `cannot` begins
 * nothing else, so the list reads the same wherever it is.
 *
 * @extends Parser<list<string>>
 */
final class ParseClauseAbilityList extends Parser
{
    use LooksAheadAtRuleEntries;

    public function __construct(private readonly string $part)
    {
    }

    protected function read(): array
    {
        if ($this->clauseEndAhead() || $this->check(TokenType::LBRACE) || $this->check(TokenType::RBRACE)) {
            return [];
        }

        return $this->parse(new ParseAbilityList($this->part))->value;
    }
}
