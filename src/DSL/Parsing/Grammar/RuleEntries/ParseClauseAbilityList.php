<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The abilities one `they can` / `they cannot` clause names after its keyword:
 * its list, or none.
 *
 * Inside an ability block the list is forbidden. The header is the one place
 * the ability is said, so every clause in the block has a single reading and
 * the header stays a complete account of what the block is about.
 *
 * A list written where none belongs, or missing where one does, is reported.
 * In rules with no `for` header the clause takes what is written; in an ability
 * block a list is read and dropped, since a clause there names none.
 *
 * @extends Parser<list<string>>
 */
final class ParseClauseAbilityList extends Parser
{
    use LooksAheadAtRuleEntries;
    use ReportsAbilityNamingMismatches;

    /**
     * @param string $part The clause's part the abilities are, as its node
     *   names it.
     */
    public function __construct(
        private readonly AbilityNaming $naming,
        private readonly string $part,
    ) {
    }

    /**
     * @return list<string>
     */
    protected function read(): array
    {
        if ($this->naming === AbilityNaming::Forbidden) {
            if (! $this->check(TokenType::IDENTIFIER) && ! $this->check(TokenType::STAR)) {
                return [];
            }

            $this->report($this->errorAtCurrent(
                'A clause inside an ability block may not name abilities; the block header already names them.'
            ));
            $this->parse(new ParseAbilityList(null));

            return [];
        }

        $names = $this->naming->names();

        if ($this->naming->followsFirstEntry()) {
            $written = ! $this->clauseEndAhead();

            if ($written !== $names) {
                $this->report($this->abilityNamingMismatchError($written, 'This clause'));
                $names = $written;
            }
        }

        return $names ? $this->parse(new ParseAbilityList($this->part))->value : [];
    }
}
