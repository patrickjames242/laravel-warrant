<?php

namespace Warrant\RuleSyntaxTree\Parsing;

use Warrant\RuleSyntaxTree\AndNode;
use Warrant\RuleSyntaxTree\ConditionNode;
use Warrant\RuleSyntaxTree\ContextRef;
use Warrant\RuleSyntaxTree\IBooleanExpressionNode;
use Warrant\RuleSyntaxTree\NotNode;
use Warrant\RuleSyntaxTree\OrNode;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantSyntaxException;

/**
 * Recursive-descent parser for Warrant rule syntax. Bindings are resolved inline
 * as the tree is built, so the resulting nodes hold only concrete values.
 *
 * Grammar:
 *   ruleset  := clauses? ( 'if' expr clause+ )*
 *   clause   := 'they' ('can'|'cannot') ability (',' ability)*
 *   ability  := IDENTIFIER | '*'
 *   expr     := or
 *   or       := and ('or' and)*
 *   and      := not ('and' not)*
 *   not      := ('not'|'!') not | primary
 *   primary  := '(' expr ')' | condition
 *   condition:= IDENTIFIER ( '(' (arg (',' arg)*)? ')' )?
 *   arg      := literal | NAMED_BINDING | POSITIONAL | context_ref
 *   context_ref := '@context' IDENTIFIER
 */
final class WarrantParser
{
    /** @var list<Token> */
    private readonly array $tokens;

    private int $index = 0;

    private readonly BindingState $bindings;

    /**
     * @param array<int|string, mixed> $bindings
     */
    private function __construct(
        private readonly string $source,
        array $bindings = [],
    ) {
        $this->tokens = (new Lexer($source))->tokenize();
        $this->bindings = new BindingState($source, $bindings);
    }

    /**
     * Parse Warrant syntax into a flat list of rules, resolving $bindings inline.
     *
     * @param array<int|string, mixed> $bindings
     * @return list<WarrantRule>
     */
    public static function parse(string $source, array $bindings = []): array
    {
        return (new self($source, $bindings))->parseComplete();
    }

    /**
     * Parse source that must contain exactly one rule.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parseSingleRule(string $source, array $bindings = []): WarrantRule
    {
        $parser = new self($source, $bindings);
        $rules = $parser->parseComplete();

        if ($rules === []) {
            throw $parser->errorAtCurrent('Expected a rule.');
        }

        if (count($rules) > 1) {
            throw $parser->errorAtCurrent('Expected a single rule but found multiple.');
        }

        return $rules[0];
    }

    /**
     * Parse a single boolean condition expression (the part after `if`), for the
     * fluent builder's `ifRaw()` bridge. No `they can/cannot` clauses.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parseConditionExpression(string $source, array $bindings = []): IBooleanExpressionNode
    {
        $parser = new self($source, $bindings);
        $expression = $parser->parseExpression();
        $parser->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
        $parser->bindings->finalize($parser->peek());

        return $expression;
    }

    /**
     * Parse the full input to rules, asserting a clean end and that every
     * binding was consumed.
     *
     * @return list<WarrantRule>
     */
    private function parseComplete(): array
    {
        $rules = $this->parseRules();
        $this->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
        $this->bindings->finalize($this->peek());

        return $rules;
    }

    /**
     * @return list<WarrantRule>
     */
    private function parseRules(): array
    {
        $rules = [];

        // Leading `they can/cannot` clauses (no `if`) form one unconditional rule.
        if ($this->check(TokenType::THEY)) {
            $rules[] = $this->parseClausesInto(null);
        }

        // Each `if` starts a new conditional rule.
        while ($this->check(TokenType::IF)) {
            $this->advance();
            $conditions = $this->parseExpression();
            $rules[] = $this->parseClausesInto($conditions);
        }

        return $rules;
    }

    private function parseClausesInto(?IBooleanExpressionNode $conditions): WarrantRule
    {
        $can = [];
        $cannot = [];
        $sawClause = false;

        while ($this->check(TokenType::THEY)) {
            $this->advance();
            $sawClause = true;

            if ($this->check(TokenType::CAN)) {
                $this->advance();
                $can = array_merge($can, $this->parseAbilityList());
            } elseif ($this->check(TokenType::CANNOT)) {
                $this->advance();
                $cannot = array_merge($cannot, $this->parseAbilityList());
            } else {
                throw $this->errorAtCurrent("Expected 'can' or 'cannot' after 'they'.");
            }
        }

        if (! $sawClause) {
            throw $this->errorAtCurrent("Expected at least one 'they can ...' or 'they cannot ...' clause.");
        }

        return new WarrantRule($conditions, $can, $cannot);
    }

    /**
     * @return list<string>
     */
    private function parseAbilityList(): array
    {
        $abilities = [$this->parseAbility()];

        while ($this->check(TokenType::COMMA)) {
            $this->advance();
            $abilities[] = $this->parseAbility();
        }

        return $abilities;
    }

    private function parseAbility(): string
    {
        if ($this->check(TokenType::STAR)) {
            $this->advance();

            return '*';
        }

        if ($this->check(TokenType::IDENTIFIER)) {
            return $this->advance()->lexeme;
        }

        throw $this->nameError('an ability name');
    }

    private function parseExpression(): IBooleanExpressionNode
    {
        return $this->parseOr();
    }

    private function parseOr(): IBooleanExpressionNode
    {
        $left = $this->parseAnd();

        while ($this->check(TokenType::OR)) {
            $this->advance();
            $left = new OrNode($left, $this->parseAnd());
        }

        return $left;
    }

    private function parseAnd(): IBooleanExpressionNode
    {
        $left = $this->parseNot();

        while ($this->check(TokenType::AND)) {
            $this->advance();
            $left = new AndNode($left, $this->parseNot());
        }

        return $left;
    }

    private function parseNot(): IBooleanExpressionNode
    {
        if ($this->check(TokenType::NOT)) {
            $this->advance();

            return new NotNode($this->parseNot());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): IBooleanExpressionNode
    {
        if ($this->check(TokenType::LPAREN)) {
            $this->advance();
            $expr = $this->parseExpression();
            $this->expect(TokenType::RPAREN, "Expected ')' to close the group.");

            return $expr;
        }

        if ($this->check(TokenType::IDENTIFIER)) {
            return $this->parseCondition();
        }

        throw $this->nameError("a condition or '('");
    }

    private function parseCondition(): ConditionNode
    {
        $name = $this->advance()->lexeme;
        $parameters = [];

        if ($this->check(TokenType::LPAREN)) {
            $this->advance();

            if (! $this->check(TokenType::RPAREN)) {
                $parameters[] = $this->parseArgument();

                while ($this->check(TokenType::COMMA)) {
                    $this->advance();
                    $parameters[] = $this->parseArgument();
                }
            }

            $this->expect(TokenType::RPAREN, "Expected ')' to close the condition arguments.");
        }

        return new ConditionNode($name, $parameters);
    }

    private function parseArgument(): mixed
    {
        $token = $this->peek();

        return match ($token->type) {
            TokenType::STRING,
            TokenType::INT,
            TokenType::FLOAT,
            TokenType::BOOL,
            TokenType::NULL => $this->advance()->value,
            TokenType::NAMED_BINDING => $this->bindings->resolveNamed($this->advance()),
            TokenType::POSITIONAL => $this->bindings->resolvePositional($this->advance()),
            TokenType::CONTEXT_REF => $this->parseContextRef(),
            default => throw $this->errorAtCurrent('Expected an argument: a literal, a binding (:name or ?), or @context <key>.'),
        };
    }

    /**
     * Parse a `@context <key>` reference into a symbolic {@see ContextRef}. It
     * bypasses {@see BindingState} entirely — it is neither a parse-time named
     * nor positional binding — so it is exempt from the "all bindings used /
     * no mixing" checks and is resolved later, at compile time.
     */
    private function parseContextRef(): ContextRef
    {
        $this->advance(); // consume '@context'

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->errorAtCurrent("Expected a context key after '@context'.");
        }

        return new ContextRef($this->advance()->lexeme);
    }

    // -- token helpers --------------------------------------------------------

    private function peek(): Token
    {
        return $this->tokens[$this->index];
    }

    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->index];

        if ($token->type !== TokenType::EOF) {
            $this->index++;
        }

        return $token;
    }

    private function expect(TokenType $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        throw $this->errorAtCurrent($message);
    }

    private function errorAtCurrent(string $message): WarrantSyntaxException
    {
        return WarrantSyntaxException::at($message, $this->source, $this->peek());
    }

    /**
     * Error for a spot expecting a name, with a clearer hint when the offending
     * token is a reserved word (which cannot be used as a name).
     */
    private function nameError(string $expected): WarrantSyntaxException
    {
        $token = $this->peek();

        if ($this->isReservedWord($token)) {
            return WarrantSyntaxException::at(
                sprintf("Reserved word '%s' cannot be used as a name; expected %s.", $token->lexeme, $expected),
                $this->source,
                $token,
            );
        }

        return WarrantSyntaxException::at(sprintf('Expected %s.', $expected), $this->source, $token);
    }

    private function isReservedWord(Token $token): bool
    {
        return in_array($token->type, [
            TokenType::IF,
            TokenType::THEY,
            TokenType::CAN,
            TokenType::CANNOT,
            TokenType::AND,
            TokenType::OR,
            TokenType::NOT,
        ], true) && ctype_alpha($token->lexeme);
    }
}
