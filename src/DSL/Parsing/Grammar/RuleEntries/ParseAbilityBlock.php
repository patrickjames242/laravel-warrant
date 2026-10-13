<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * An ability block: `can they <ability>, ... { <rules> }`, whose rules take the
 * header's abilities instead of naming any.
 *
 * The block is grouping and nothing more. Its entries are generic, as the
 * source writes them, and the header alone says which abilities they take;
 * expansion applies it ({@see \Warrant\DSL\Expanding\RuleSetExpander}).
 *
 * The block is read as it is written: a header naming no ability, an entry
 * naming its own, or a block inside it are the validator's to report.
 *
 * Read only where `they` follows `can`, as
 * {@see LooksAheadAtRuleEntries::ruleEntryAhead()} tells before an entry is
 * read: `can` alone starts a `can(...)` expression.
 *
 * @extends Parser<AbilityBlockNode>
 */
final class ParseAbilityBlock extends Parser
{
    protected static function startsWith(): TokenType
    {
        return TokenType::CAN;
    }

    protected function read(): AbilityBlockNode
    {
        $this->advance(); // consume 'can'
        $this->advance(); // consume 'they'

        $abilities = $this->parse(new ParseClauseAbilityList(AbilityBlockNode::PART_ABILITIES))->value;

        $this->expect(TokenType::LBRACE, "Expected '{' to open the ability block body.");
        $entries = $this->parse(ParseRuleEntries::class)->value;
        $this->expect(TokenType::RBRACE, "Expected '}' to close the ability block body.");

        return new AbilityBlockNode($abilities, $entries);
    }
}
