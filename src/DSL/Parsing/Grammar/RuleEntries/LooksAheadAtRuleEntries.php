<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * What the tokens ahead say about rule entries: whether one starts here,
 * whether a clause has just ended, whether the entries end here, and where
 * reading can go on after a mistake among them. Used by parsers of the grammar,
 * which supply the token helpers these read with.
 */
trait LooksAheadAtRuleEntries
{
    /**
     * Whether a rule entry starts here: the entries' own starts, so this agrees
     * with what {@see ParseRuleEntry} reads.
     */
    private function ruleEntryAhead(): bool
    {
        return ParseRuleEntry::startsHere($this->tokensAhead());
    }

    /**
     * Whether the rule entries of a body end here: at the `}` that closes it,
     * the `for` or `{` of a block after it, or the end of the input. Whatever
     * reads the body says whether that end is the right one.
     *
     * @param TokensAhead $ahead The tokens from where the next entry would start.
     */
    private static function ruleEntriesEndHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::RBRACE)
            || $ahead->nextIs(TokenType::LBRACE)
            || $ahead->nextIs(TokenType::FOR)
            || $ahead->nextIs(TokenType::EOF);
    }

    /**
     * Whether reading can go on here after a mistake among rule entries: where
     * the entries end, or where an `if` rule, an `@include` or an ability block
     * starts. A rule of bare `they` clauses is not one, because a `they` after a
     * mistake most often goes on with the rule the mistake is in, whose clauses
     * are then skipped with it.
     *
     * @param TokensAhead $ahead The tokens from where the parse has skipped to.
     */
    private static function ruleEntriesResumeHere(TokensAhead $ahead): bool
    {
        return self::ruleEntriesEndHere($ahead)
            || $ahead->nextIs(TokenType::IF)
            || $ahead->nextIs(TokenType::INCLUDE_REF)
            || $ahead->nextIs(TokenType::CAN, TokenType::THEY);
    }

    /**
     * Whether the next token follows a finished `they can` / `they cannot`
     * clause: the start of another clause or entry, a `because`, a `for` header,
     * or the end of the input. In rules with no `for` header, a clause leaves
     * out its abilities exactly when one of these follows its keyword, so a
     * reserved word written as an ability is reported as one rather than as an
     * ability-less clause followed by a stray token.
     */
    private function clauseEndAhead(): bool
    {
        return $this->ruleEntryAhead()
            || $this->check(TokenType::BECAUSE)
            || $this->check(TokenType::FOR)
            || $this->check(TokenType::EOF);
    }

    /**
     * Report an ability list sitting where a rule or a condition should start.
     * That is the block header written without its `can they`, and neither
     * "expected end of input" nor an expression error would name the mistake.
     */
    private function assertNoAbilityListWithoutCanThey(): void
    {
        if (($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::STAR))
            && in_array($this->peekAhead()->type, [TokenType::LBRACE, TokenType::COMMA], true)) {
            $this->report($this->errorAtCurrent(
                'An ability block header is written `can they <ability>, ... { ... }`.'
            ));
        }
    }
}
