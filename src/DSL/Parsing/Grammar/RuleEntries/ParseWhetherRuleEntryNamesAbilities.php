<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * Whether the rule entry here, written with no `for` header, names the
 * abilities it applies to; {@see NOTHING} when no rule entry starts here. Read with
 * {@see \Warrant\DSL\Parsing\Parsers\Parser::lookahead()}, ahead of reading the
 * entries for real.
 *
 * An ability block names them in its header. An include names them when a
 * `for` list follows its arguments. A clause, after its `if` condition when
 * it has one, names them unless its `can` / `cannot` is followed by what
 * follows a finished clause.
 *
 * The condition and the arguments are stepped over rather than read, so this
 * never fails. Where they are malformed, whatever the answer, reading the entry
 * for real fails at the same place, before its naming is looked at.
 *
 * @extends Parser<bool>
 */
final class ParseWhetherRuleEntryNamesAbilities extends Parser
{
    use LooksAheadAtRuleEntries;

    protected function read(): bool|NoMatch
    {
        if ($this->abilityBlockAhead()) {
            return true;
        }

        if ($this->check(TokenType::INCLUDE_REF)) {
            $this->advance();

            if (! $this->check(TokenType::IDENTIFIER)) {
                return false;
            }

            $this->advance();
            $this->skipParenthesized();

            return $this->check(TokenType::FOR);
        }

        if ($this->check(TokenType::IF)) {
            // A condition never holds a `they`, so the first one ends it.
            while (! $this->check(TokenType::THEY) && ! $this->check(TokenType::EOF)) {
                $this->advance();
            }
        } elseif (! $this->check(TokenType::THEY)) {
            return self::NOTHING;
        }

        if (! $this->check(TokenType::THEY)) {
            return false;
        }

        $this->advance();

        if (! $this->check(TokenType::CAN) && ! $this->check(TokenType::CANNOT)) {
            return false;
        }

        $this->advance();

        return ! $this->clauseEndAhead();
    }

    /**
     * Step over the parenthesized text here, to its matching `)` or the end of
     * the input, when a `(` opens it.
     */
    private function skipParenthesized(): void
    {
        if (! $this->check(TokenType::LPAREN)) {
            return;
        }

        $depth = 0;

        do {
            if ($this->check(TokenType::LPAREN)) {
                $depth++;
            } elseif ($this->check(TokenType::RPAREN)) {
                $depth--;
            }

            $this->advance();
        } while ($depth > 0 && ! $this->check(TokenType::EOF));
    }
}
