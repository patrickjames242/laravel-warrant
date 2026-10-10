<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * A parser of Warrant rule syntax: what every parser of the grammar shares
 * beyond reading tokens.
 *
 * @template-covariant T
 * @extends Parser<T>
 */
abstract class GrammarParser extends Parser
{
    /**
     * The keywords that are spelled as words, and so could be mistaken for a
     * name.
     */
    private const RESERVED_WORDS = [
        TokenType::IF,
        TokenType::THEY,
        TokenType::CAN,
        TokenType::CANNOT,
        TokenType::BECAUSE,
        TokenType::CHECK,
        TokenType::AND,
        TokenType::OR,
        TokenType::NOT,
        TokenType::FOR,
        TokenType::WITH,
        TokenType::AS,
    ];

    /**
     * Error for a spot expecting a name, with a clearer hint when the offending
     * token is a reserved word (which cannot be used as a name).
     */
    final protected function nameError(string $expected): WarrantSyntaxException
    {
        $token = $this->peek();

        // `!` is a NOT too; only a keyword spelled as a word looks like a name.
        if (in_array($token->type, self::RESERVED_WORDS, true) && ctype_alpha($token->lexeme)) {
            return $this->errorAtCurrent(
                sprintf("Reserved word '%s' cannot be used as a name; expected %s.", $token->lexeme, $expected)
            );
        }

        return $this->errorAtCurrent(sprintf('Expected %s.', $expected));
    }

    /**
     * Error for a spot where a boolean expression must start and none does.
     */
    final protected function missingBooleanExpressionError(): WarrantSyntaxException
    {
        return $this->nameError("a condition, 'can(', 'check(', or '('");
    }

    /**
     * Error for a spot where an argument must be and none is.
     */
    final protected function missingArgumentError(): WarrantSyntaxException
    {
        return $this->errorAtCurrent(
            'Expected an argument: a literal, a binding (:name or ?), @context <key>, '
                .'@column <column> or @column <name>.<column>, or @sql "<sql>".'
        );
    }

    /**
     * Error for an entry in rules with no `for` header that names abilities
     * when the first entry named none, or names none when the first named
     * theirs.
     *
     * @param bool $names Whether the entry being read names abilities.
     * @param string $entry The entry being read, for the message.
     */
    final protected function abilityNamingMismatchError(bool $names, string $entry): WarrantSyntaxException
    {
        return $this->errorAtCurrent(sprintf(
            '%s %s, but the rules before it %s; rules with no `for` header either all name their abilities '
                .'or all leave them to be named where the rules are placed.',
            $entry,
            $names ? 'names abilities' : 'names none',
            $names ? 'name none' : 'name theirs',
        ));
    }

    /**
     * Whether an ability block starts here: the `can they` that heads one. A
     * `can(` is an expression, and the clause keyword is reached as `they can`,
     * the other way round.
     */
    final protected function abilityBlockAhead(): bool
    {
        return $this->check(TokenType::CAN) && $this->peekAhead()->type === TokenType::THEY;
    }

    /**
     * Whether a rule entry starts here. These four are the only tokens that can
     * open one, and none of them can open an expression.
     */
    final protected function ruleEntryAhead(): bool
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
    final protected function clauseEndAhead(): bool
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
    final protected function assertNoAbilityListWithoutCanThey(): void
    {
        if (($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::STAR))
            && in_array($this->peekAhead()->type, [TokenType::LBRACE, TokenType::COMMA], true)) {
            throw $this->errorAtCurrent(
                'An ability block header is written `can they <ability>, ... { ... }`.'
            );
        }
    }
}
