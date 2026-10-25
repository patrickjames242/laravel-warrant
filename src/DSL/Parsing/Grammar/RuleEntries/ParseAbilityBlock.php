<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * An ability block: `can they <ability>, ... { <rules> }`, whose rules take the
 * header's abilities instead of naming any.
 *
 * The block is grouping and nothing more. Its entries are generic, as the
 * source writes them, and the header alone says which abilities they take;
 * expansion applies it ({@see \Warrant\DSL\Expanding\Expander}).
 *
 * The block is read as it is written: a header naming no ability, an entry
 * naming its own, or a block inside it are the validator's to report.
 *
 * @extends Parser<AbilityBlockNode>
 */
final class ParseAbilityBlock extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        // `can` alone starts a `can(...)` expression.
        return $ahead->nextIs(TokenType::CAN, TokenType::THEY);
    }

    protected function read(): AbilityBlockNode
    {
        $this->advance(); // consume 'can'
        $this->advance(); // consume 'they'

        $abilities = $this->parse(ParseCanOrCannotAbilityList::class)->value;

        $this->expectOrAssume(TokenType::LBRACE, "Expected '{' to open the ability block body.");
        $unclosed = "Expected '}' to close the ability block body.";
        $entries = $this->parse(new ParseRuleEntries($unclosed))->value;
        $this->expectOrAssume(TokenType::RBRACE, $unclosed);

        return new AbilityBlockNode($abilities, $entries, $this->locationEndingHere());
    }
}
