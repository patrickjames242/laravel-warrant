<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;

/**
 * What the tokens ahead say about rule entries: whether one starts here, or
 * whether a clause has just ended. Used by parsers of the grammar, which supply
 * the token helpers these read with.
 */
trait LooksAheadAtRuleEntries
{
    /**
     * Whether an ability block starts here: the `can they` that heads one. A
     * `can(` is an expression, and the clause keyword is reached as `they can`,
     * the other way round.
     */
    private function abilityBlockAhead(): bool
    {
        return $this->check(TokenType::CAN) && $this->peekAhead()->type === TokenType::THEY;
    }

    /**
     * Whether a rule entry starts here. These four are the only tokens that can
     * open one, and none of them can open an expression.
     */
    private function ruleEntryAhead(): bool
    {
        return $this->check(TokenType::THEY)
            || $this->check(TokenType::IF)
            || $this->check(TokenType::INCLUDE_REF)
            || $this->abilityBlockAhead();
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
     * Reject an ability list sitting where a rule or a condition should start.
     * That is the block header written without its `can they`, and neither
     * "expected end of input" nor an expression error would name the mistake.
     */
    private function assertNoAbilityListWithoutCanThey(): void
    {
        if (($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::STAR))
            && in_array($this->peekAhead()->type, [TokenType::LBRACE, TokenType::COMMA], true)) {
            throw $this->errorAtCurrent(
                'An ability block header is written `can they <ability>, ... { ... }`.'
            );
        }
    }
}
