<?php

namespace Warrant\RuleSyntaxTree\Parsing;

use Closure;
use Warrant\RuleSyntaxTree\AndNode;
use Warrant\RuleSyntaxTree\CannotClause;
use Warrant\RuleSyntaxTree\ConditionNode;
use Warrant\RuleSyntaxTree\ContextRef;
use Warrant\RuleSyntaxTree\CrossSchemaCanNode;
use Warrant\RuleSyntaxTree\CrossSchemaConditionNode;
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
 *   clause   := 'they' ( 'can' ability (',' ability)*
 *                      | 'cannot' ability (',' ability)* ( 'because' message )? )
 *              -- `because` attaches a denial message; valid only after `cannot`.
 *                 Each `they cannot ...` clause becomes one CannotClause on the
 *                 rule, so distinct clauses keep distinct messages.
 *   message  := STRING | NAMED_BINDING | POSITIONAL
 *              -- a string literal, or a binding resolving to a string or closure
 *   ability  := IDENTIFIER | '*'
 *   expr     := or
 *   or       := and ('or' and)*
 *   and      := not ('and' not)*
 *   not      := ('not'|'!') not | primary
 *   primary  := '(' expr ')' | can_expr | check_expr | condition
 *   can_expr := 'can' '(' IDENTIFIER 'for' handle ( 'with' with_map )? ')'
 *   check_expr := 'check' '(' expr 'for' handle ( 'with' with_map )? ')'
 *              -- the inner expr is a boolean tree of the target schema's conditions
 *   handle   := IDENTIFIER ( '(' arg ')' )?   -- no parens: unbound; one arg: row-bound
 *   with_map := with_entry (',' with_entry)*
 *   with_entry := IDENTIFIER '=' arg
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

    /**
     * Parse the `they can/cannot` clauses that share one condition (the clauses
     * after an `if`, or the leading clauses with none) into a single rule. Each
     * `they cannot <abilities> [because <msg>]` clause becomes one
     * {@see CannotClause}, so distinct clauses keep distinct messages on the same
     * rule.
     */
    private function parseClausesInto(?IBooleanExpressionNode $conditions): WarrantRule
    {
        $can = [];
        $cannotClauses = [];
        $sawClause = false;

        while ($this->check(TokenType::THEY)) {
            $this->advance();
            $sawClause = true;

            if ($this->check(TokenType::CAN)) {
                $this->advance();
                $can = array_merge($can, $this->parseAbilityList());

                // A `because` message only ever surfaces for a matching `cannot`;
                // hanging one off a `can` clause can never fire, so reject it here.
                if ($this->check(TokenType::BECAUSE)) {
                    throw $this->errorAtCurrent(
                        "'because' may only follow a 'they cannot ...' clause, not 'they can ...'."
                    );
                }
            } elseif ($this->check(TokenType::CANNOT)) {
                $this->advance();
                $abilities = $this->parseAbilityList();

                $message = null;

                if ($this->check(TokenType::BECAUSE)) {
                    $this->advance();
                    $message = $this->parseDenialMessage();
                }

                $cannotClauses[] = new CannotClause($abilities, $message);
            } else {
                throw $this->errorAtCurrent("Expected 'can' or 'cannot' after 'they'.");
            }
        }

        if (! $sawClause) {
            throw $this->errorAtCurrent("Expected at least one 'they can ...' or 'they cannot ...' clause.");
        }

        return new WarrantRule($conditions, $can, $cannotClauses);
    }

    /**
     * Parse the denial message after `because`. Accepts a quoted string literal
     * or a `:name`/`?` binding; a literal must be a string (no numbers/bools),
     * and `@context` is not allowed — a message is fixed at parse time, not
     * resolved per check. A binding may resolve to a string *or* to a closure
     * (the `Closure(WarrantDenialContext): string|Throwable` message form), so
     * dynamic messages can still be carried through the DSL via a binding.
     */
    private function parseDenialMessage(): string|Closure
    {
        $token = $this->peek();

        $message = match ($token->type) {
            TokenType::STRING => $this->advance()->value,
            TokenType::NAMED_BINDING => $this->bindings->resolveNamed($this->advance()),
            TokenType::POSITIONAL => $this->bindings->resolvePositional($this->advance()),
            default => throw $this->errorAtCurrent(
                "Expected a denial message after 'because': a quoted string or a binding (:name or ?). "
                . '@context is not allowed here.'
            ),
        };

        if (! is_string($message) && ! $message instanceof Closure) {
            throw WarrantSyntaxException::at(
                sprintf('A denial message must be a string or a closure, got %s.', get_debug_type($message)),
                $this->source,
                $token,
            );
        }

        return $message;
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

        if ($this->check(TokenType::CAN)) {
            return $this->parseCan();
        }

        if ($this->check(TokenType::CHECK)) {
            return $this->parseCheck();
        }

        if ($this->check(TokenType::IDENTIFIER)) {
            return $this->parseCondition();
        }

        throw $this->nameError("a condition, 'can(', 'check(', or '('");
    }

    /**
     * Parse a cross-schema ability check: `can(<ability> for <handle> [with <map>])`.
     *
     * In expression position `can` is unambiguously this builtin — the clause
     * keyword in `they can ...` is consumed by {@see parseClausesInto()} and never
     * reaches here — so no lookahead is needed.
     */
    private function parseCan(): CrossSchemaCanNode
    {
        $this->advance(); // consume 'can'
        $this->expect(TokenType::LPAREN, "Expected '(' after 'can'.");

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('an ability name');
        }

        $ability = $this->advance()->lexeme;

        $this->expect(TokenType::FOR, "Expected 'for' after the ability name in 'can(...)'.");

        [$schemaKey, $isRowBound, $boundRow] = $this->parseHandle();

        $contextMap = [];

        if ($this->check(TokenType::WITH)) {
            $this->advance();
            $contextMap = $this->parseWithMap();
        }

        $this->expect(TokenType::RPAREN, "Expected ')' to close 'can(...)'.");

        return new CrossSchemaCanNode($schemaKey, $ability, $isRowBound, $boundRow, $contextMap);
    }

    /**
     * Parse a cross-schema condition check:
     * `check(<predicate> for <handle> [with <map>])`.
     *
     * The predicate is a full boolean expression whose leaves are the target
     * schema's conditions; {@see parseExpression()} consumes it and naturally
     * stops at `for`, which is neither an operator nor the start of a primary.
     * Like `can`, `check` in expression position is unambiguously this builtin.
     */
    private function parseCheck(): CrossSchemaConditionNode
    {
        $this->advance(); // consume 'check'
        $this->expect(TokenType::LPAREN, "Expected '(' after 'check'.");

        $predicate = $this->parseExpression();

        $this->expect(TokenType::FOR, "Expected 'for' after the condition predicate in 'check(...)'.");

        [$schemaKey, $isRowBound, $boundRow] = $this->parseHandle();

        $contextMap = [];

        if ($this->check(TokenType::WITH)) {
            $this->advance();
            $contextMap = $this->parseWithMap();
        }

        $this->expect(TokenType::RPAREN, "Expected ')' to close 'check(...)'.");

        return new CrossSchemaConditionNode($schemaKey, $predicate, $isRowBound, $boundRow, $contextMap);
    }

    /**
     * Parse a cross-schema handle: a schema name with an optional row selector
     * `schema(<arg>)`. The selector's absence marks an unbound (no-row) handle.
     *
     * @return array{0: string, 1: bool, 2: mixed} [schemaKey, isRowBound, boundRow]
     */
    private function parseHandle(): array
    {
        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('a schema name');
        }

        $schemaKey = $this->advance()->lexeme;

        $isRowBound = false;
        $boundRow = null;

        if ($this->check(TokenType::LPAREN)) {
            $this->advance();
            $isRowBound = true;
            $boundRow = $this->parseArgument();
            $this->expect(TokenType::RPAREN, "Expected ')' to close the row selector.");
        }

        return [$schemaKey, $isRowBound, $boundRow];
    }

    /**
     * Parse a `with` context map: `key = arg (, key = arg)*`. Keys are the target
     * schema's context key names; duplicate keys are rejected.
     *
     * @return array<string, mixed>
     */
    private function parseWithMap(): array
    {
        $map = [];

        do {
            if (! $this->check(TokenType::IDENTIFIER)) {
                throw $this->nameError('a context key name');
            }

            $keyToken = $this->advance();
            $key = $keyToken->lexeme;

            if (array_key_exists($key, $map)) {
                throw WarrantSyntaxException::at(
                    sprintf("Duplicate key '%s' in the 'with' map.", $key),
                    $this->source,
                    $keyToken,
                );
            }

            $this->expect(TokenType::EQUALS, "Expected '=' after the 'with' key.");
            $map[$key] = $this->parseArgument();
        } while ($this->check(TokenType::COMMA) && $this->advance());

        return $map;
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
            TokenType::BECAUSE,
            TokenType::CHECK,
            TokenType::AND,
            TokenType::OR,
            TokenType::NOT,
            TokenType::FOR,
            TokenType::WITH,
        ], true) && ctype_alpha($token->lexeme);
    }
}
