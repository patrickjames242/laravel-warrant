<?php

namespace Warrant\DSL\Parsing;

use Closure;
use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\Rules\CannotClause;
use Warrant\Rules\IncludeInvocation;
use Warrant\Rules\RuleSetEntry;
use Warrant\Rules\WarrantRule;

/**
 * Recursive-descent parser for Warrant rule syntax. Bindings are resolved inline
 * as the tree is built, so the resulting nodes hold only concrete values.
 *
 * Grammar:
 *   group    := block*                               -- one or more `for` blocks (RuleSetGroup)
 *   block    := 'for' IDENTIFIER '{' ruleset '}'     -- header + braces mandatory in a group
 *   header   := 'for' IDENTIFIER                     -- optional schema header on a lone rule/ruleset
 *   ruleset  := ( clauses | 'if' expr clause+ | ability_block | include )*
 *              -- consecutive `they` clauses merge into one unconditional rule
 *   ability_block := ability (',' ability)* '{' ruleset '}'
 *              -- the header says the abilities once, so clauses inside are
 *                 headless and may not name their own; a block never contains
 *                 another
 *   include  := '@include' IDENTIFIER ( '(' (arg (',' arg)*)? ')' )?
 *                          ( 'for' ability (',' ability)* )?
 *              -- expands a schema's rule template. The `for` list is required
 *                 outside an ability block and forbidden inside one, where the
 *                 header already names the abilities
 *   clause   := 'they' ( 'can' ability (',' ability)*
 *                      | 'cannot' ability (',' ability)* ( 'because' message )? )
 *              -- `because` attaches a denial message; valid only after `cannot`.
 *                 Each `they cannot ...` clause becomes one CannotClause on the
 *                 rule, so distinct clauses keep distinct messages.
 *                 Inside an ability block the ability list is omitted entirely.
 *   message  := STRING | NAMED_BINDING | POSITIONAL
 *              -- a string literal, or a binding resolving to a string or closure
 *   ability  := IDENTIFIER | '*'
 *   expr     := or
 *   or       := and ('or' and)*
 *   and      := not ('and' not)*
 *   not      := ('not'|'!') not | primary
 *   primary  := '(' expr ')' | can_expr | check_expr | condition
 *   can_expr := 'can' '(' IDENTIFIER ( 'for' handle ( 'with' with_map )? )? ')'
 *              -- without `for` nothing is crossed: another ability of this
 *                 schema, over the row and context the rule already has
 *   check_expr := 'check' '(' expr 'for' handle ( 'with' with_map )? ')'
 *              -- the inner expr is a boolean tree of the target schema's conditions
 *   handle   := IDENTIFIER ( '(' arg ')' )? ( 'as' IDENTIFIER )?
 *              -- no parens: unbound; one arg: row-bound. `as` names the rows this
 *                 reference selects, so a @column can tell them apart from a table
 *                 of the same name further out; only a row-bound handle may take one.
 *   with_map := with_entry (',' with_entry)*
 *   with_entry := IDENTIFIER '=' arg
 *   condition:= IDENTIFIER ( '(' (arg (',' arg)*)? ')' )?
 *   arg      := literal | NAMED_BINDING | POSITIONAL | context_ref | column_ref
 *   context_ref := '@context' IDENTIFIER
 *   column_ref  := '@column' IDENTIFIER ( '.' IDENTIFIER )?
 *              -- one name is the column, on the rows the rule is already about;
 *                 two are a frame name (a schema key, or a handle alias) and then
 *                 the column
 */
final class WarrantParser
{
    /**
     * Raised from the two places a nested block can be noticed: the body loop, and
     * the ability slot of a clause, where `they can edit { ... }` reaches the
     * clause parser first.
     */
    private const NESTED_ABILITY_BLOCK =
        'An ability block may not contain another; the enclosing block already names the abilities.';

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
     * Reject an `@include` where the caller has nowhere to carry one. Both places
     * answer with rules alone: an include is expanded against a schema's templates
     * and so belongs to a rule set, which is what holds it.
     *
     * @param list<RuleSetEntry> $entries
     * @return list<\Warrant\Rules\WarrantRule>
     */
    private function assertNoIncludes(array $entries, string $construct): array
    {
        foreach ($entries as $entry) {
            if ($entry instanceof IncludeInvocation) {
                throw $this->errorAtCurrent(sprintf(
                    'An @include is not valid for %s; use WarrantRuleSet::fromSyntax, which carries it to the '
                        .'schema whose rule template it expands.',
                    $construct,
                ));
            }
        }

        return $entries;
    }

    /**
     * Parse source that must contain exactly one rule, preceded by an optional
     * `for <schema>` header. Curly braces are rejected — a `{ ... }` block wraps a
     * rule *set*, not a single rule. The header schema (or null) is baked onto the
     * returned rule via {@see WarrantRule::withSchemaKey()}; header/param
     * reconciliation happens in {@see WarrantRule::fromSyntax()}.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parseSingleRule(string $source, array $bindings = []): WarrantRule
    {
        $parser = new self($source, $bindings);

        $schemaKey = $parser->parseOptionalHeader();

        if ($parser->check(TokenType::LBRACE)) {
            throw $parser->errorAtCurrent(
                'Curly braces are not valid for a single rule; use WarrantRuleSet::fromSyntax for a `{ ... }` block.'
            );
        }

        /* An ability block wraps a rule *set* as surely as braces do. Rejecting it
           here rather than leaving it to the count below keeps the error about the
           construct: a block holding one rule would otherwise pass, and a block
           holding two would fail as 'multiple rules', which names the wrong
           mistake. */
        if ($parser->abilityBlockAhead()) {
            throw $parser->errorAtCurrent(
                'An ability block is not valid for a single rule; use WarrantRuleSet::fromSyntax for a `<ability> { ... }` block.'
            );
        }

        $rules = $parser->assertNoIncludes($parser->parseRules(), 'a single rule');

        if ($rules === []) {
            throw $parser->errorAtCurrent('Expected a rule.');
        }

        if (count($rules) > 1) {
            throw $parser->errorAtCurrent('Expected a single rule but found multiple.');
        }

        $parser->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
        $parser->bindings->finalize($parser->peek());

        return $rules[0]->withSchemaKey($schemaKey);
    }

    /**
     * Parse exactly one rule set: an optional `for <schema>` header, then either a
     * braced `{ ... }` body or a bare rule body. A second `for`/`{` block is
     * rejected — multiple schemas belong in a {@see parseGroup()}. The header
     * schema may be null; the "a rule set must name a schema" check happens in
     * {@see \Warrant\Rules\WarrantRuleSet::fromSyntax()} after it is
     * reconciled with the `$schema` argument.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parseSingleRuleSet(string $source, array $bindings = []): ParsedRuleSet
    {
        $parser = new self($source, $bindings);

        $schemaKey = $parser->parseOptionalHeader();

        $entries = $parser->check(TokenType::LBRACE)
            ? $parser->parseBracedBody()
            : $parser->parseRules();

        if ($parser->check(TokenType::FOR) || $parser->check(TokenType::LBRACE)) {
            throw $parser->errorAtCurrent(
                'A single rule set targets one schema; use RuleSetGroup::fromSyntax for multiple `for ... { }` blocks.'
            );
        }

        $parser->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
        $parser->bindings->finalize($parser->peek());

        return new ParsedRuleSet($schemaKey, $entries);
    }

    /**
     * Parse a group of one or more `for <schema> { ... }` blocks. Both the `for`
     * header and the braces are mandatory on every block. Blocks are returned in
     * source order and are NOT merged — {@see \Warrant\Rules\RuleSetGroup}
     * folds same-schema blocks together. An empty (or whitespace/comment-only)
     * source yields an empty list.
     *
     * @param array<int|string, mixed> $bindings
     * @return list<ParsedRuleSet>
     */
    public static function parseGroup(string $source, array $bindings = []): array
    {
        $parser = new self($source, $bindings);

        $blocks = [];

        while (! $parser->check(TokenType::EOF)) {
            if (! $parser->check(TokenType::FOR)) {
                throw $parser->errorAtCurrent(
                    'Expected `for <schema> { ... }`; every block in a rule set group needs a `for` header and braces.'
                );
            }

            $schemaKey = $parser->parseOptionalHeader();
            $blocks[] = new ParsedRuleSet($schemaKey, $parser->parseBracedBody());
        }

        $parser->bindings->finalize($parser->peek());

        return $blocks;
    }

    /**
     * Parse a single boolean condition expression (the part after `if`), for the
     * fluent builder's `ifRaw()` bridge and {@see \Warrant\Facades\Warrant::condition()}.
     * No `they can/cannot` clauses.
     *
     * An optional `for <schema>` header is accepted here as it is for a rule and a
     * rule set, and then discarded — an expression has no schema field to carry it,
     * and this parser consults no registry, so nothing could be resolved from it
     * anyway. It is allowed so that a condition written as a string is as
     * checkable as every other construct: tooling reading the source learns which
     * schema's conditions the names belong to, which is the one thing it cannot
     * infer from an expression alone.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parseConditionExpression(string $source, array $bindings = []): IBooleanExpressionNode
    {
        $parser = new self($source, $bindings);

        $parser->parseOptionalHeader();

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
        $rules = $this->assertNoIncludes($this->parseRules(), 'a flat list of rules');
        $this->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
        $this->bindings->finalize($this->peek());

        return $rules;
    }

    /**
     * Parse an optional leading `for <schema>` header, returning the schema key,
     * or null when no header is present.
     */
    private function parseOptionalHeader(): ?string
    {
        if (! $this->check(TokenType::FOR)) {
            return null;
        }

        $this->advance(); // consume 'for'

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('a schema name after `for`');
        }

        return $this->advance()->lexeme;
    }

    /**
     * Parse a braced `{ <rules> }` body. {@see parseRules()} already stops when
     * the next token starts neither a rule nor an ability block, so it naturally
     * halts at the closing `}`.
     *
     * @return list<RuleSetEntry>
     */
    private function parseBracedBody(): array
    {
        $this->expect(TokenType::LBRACE, "Expected '{' to open the rule set body.");
        $entries = $this->parseRules();
        $this->expect(TokenType::RBRACE, "Expected '}' to close the rule set body.");

        return $entries;
    }

    /**
     * Parse a rule body: unconditional clauses, `if` rules, ability blocks and
     * `@include` directives, in any order.
     *
     * What comes back is one flat list in source order. An include keeps its place
     * among the rules because expansion splices the template's rules in where it
     * was written, so the position is part of what the include means; a block
     * contributes its own entries to the same list.
     *
     * @param list<string>|null $impliedAbilities The abilities a headless clause
     *   here takes, or null at a level where every clause names its own. A
     *   non-null value also marks this body as the inside of an ability block,
     *   which is what makes a further block a nesting error.
     * @return list<RuleSetEntry>
     */
    private function parseRules(?array $impliedAbilities = null): array
    {
        $entries = [];

        while (true) {
            /* `they` clauses with no `if` form one unconditional rule.
               parseClausesInto() absorbs every consecutive `they`, so this is
               reachable only at the start of a body or after an ability block. */
            if ($this->check(TokenType::THEY)) {
                $entries[] = $this->parseClausesInto(null, $impliedAbilities);

                continue;
            }

            if ($this->check(TokenType::INCLUDE_REF)) {
                $entries[] = $this->parseInclude($impliedAbilities);

                continue;
            }

            // Each `if` starts a new conditional rule.
            if ($this->check(TokenType::IF)) {
                $this->advance();
                $conditions = $this->parseExpression();
                $entries[] = $this->parseClausesInto($conditions, $impliedAbilities);

                continue;
            }

            if ($this->abilityBlockAhead()) {
                if ($impliedAbilities !== null) {
                    throw $this->errorAtCurrent(self::NESTED_ABILITY_BLOCK);
                }

                $entries = [...$entries, ...$this->parseAbilityBlock()];

                continue;
            }

            return $entries;
        }
    }

    /**
     * Parse an `@include`: the template to expand, its arguments, and the
     * abilities the clauses it expands to will take.
     *
     * Those abilities are named in the text either way — by the enclosing block's
     * header, or by the reference's own `for` list — so they are settled here. A
     * `for` list inside a block is rejected for the reason a clause's ability list
     * is: the header is the one place the ability is said.
     *
     * @param list<string>|null $impliedAbilities
     */
    private function parseInclude(?array $impliedAbilities): IncludeInvocation
    {
        $this->advance(); // consume '@include'

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('a rule template name');
        }

        $templateKey = $this->advance()->lexeme;
        $arguments = [];

        if ($this->check(TokenType::LPAREN)) {
            $this->advance();

            if (! $this->check(TokenType::RPAREN)) {
                $arguments[] = $this->parseArgument();

                while ($this->check(TokenType::COMMA)) {
                    $this->advance();
                    $arguments[] = $this->parseArgument();
                }
            }

            $this->expect(TokenType::RPAREN, "Expected ')' to close the @include arguments.");
        }

        if ($impliedAbilities !== null) {
            if ($this->check(TokenType::FOR)) {
                throw $this->errorAtCurrent(
                    'An @include inside an ability block may not name abilities; the block header already names them.'
                );
            }

            return new IncludeInvocation($templateKey, $arguments, $impliedAbilities);
        }

        if (! $this->check(TokenType::FOR)) {
            throw $this->errorAtCurrent(
                'An @include outside an ability block must name the abilities it applies to, as '
                    .'`@include <template> for <ability>, ...`.'
            );
        }

        $this->advance();

        return new IncludeInvocation($templateKey, $arguments, $this->parseAbilityList());
    }

    /**
     * Parse an ability block: an ability list, then a braced body whose clauses
     * take those abilities instead of naming any.
     *
     * The block is grouping and nothing more. It yields the rules the longhand
     * clauses would, and rule order never matters, so the two forms are
     * indistinguishable once parsed — which is why nothing downstream of the
     * parser knows blocks exist.
     *
     * @return list<RuleSetEntry>
     */
    private function parseAbilityBlock(): array
    {
        $abilities = $this->parseAbilityList();

        $this->expect(TokenType::LBRACE, "Expected '{' to open the ability block body.");
        $entries = $this->parseRules($abilities);
        $this->expect(TokenType::RBRACE, "Expected '}' to close the ability block body.");

        return $entries;
    }

    /**
     * Whether an ability block starts here: an ability name or `*`, followed by
     * the `{` that opens its body or the `,` that continues its header.
     *
     * Two tokens settle it. A bare name is legal nowhere else at the start of a
     * rule — only `if`, `they`, `}`, `for` or end of input can follow one — so
     * anything matching here is a block header and nothing else.
     */
    private function abilityBlockAhead(): bool
    {
        if (! $this->check(TokenType::IDENTIFIER) && ! $this->check(TokenType::STAR)) {
            return false;
        }

        return in_array($this->peekAhead()->type, [TokenType::LBRACE, TokenType::COMMA], true);
    }

    /**
     * Parse the `they can/cannot` clauses that share one condition (the clauses
     * after an `if`, or the leading clauses with none) into a single rule. Each
     * `they cannot <abilities> [because <msg>]` clause becomes one
     * {@see CannotClause}, so distinct clauses keep distinct messages on the same
     * rule.
     */
    private function parseClausesInto(
        ?IBooleanExpressionNode $conditions,
        ?array $impliedAbilities,
    ): WarrantRule {
        $can = [];
        $cannotClauses = [];
        $sawClause = false;

        while ($this->check(TokenType::THEY)) {
            $this->advance();
            $sawClause = true;

            if ($this->check(TokenType::CAN)) {
                $this->advance();
                $can = array_merge($can, $this->parseClauseAbilities($impliedAbilities));

                // A `because` message only ever surfaces for a matching `cannot`;
                // hanging one off a `can` clause can never fire, so reject it here.
                if ($this->check(TokenType::BECAUSE)) {
                    throw $this->errorAtCurrent(
                        "'because' may only follow a 'they cannot ...' clause, not 'they can ...'."
                    );
                }
            } elseif ($this->check(TokenType::CANNOT)) {
                $this->advance();
                $abilities = $this->parseClauseAbilities($impliedAbilities);

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
     * The abilities one clause applies to: the list it names, or the ones the
     * enclosing ability block supplies.
     *
     * Inside a block the list is not optional but forbidden. The header is the one
     * place the ability is said, so every clause in the block has a single reading
     * and the header stays a complete account of what the block is about.
     *
     * @param list<string>|null $impliedAbilities
     * @return list<string>
     */
    private function parseClauseAbilities(?array $impliedAbilities): array
    {
        if ($impliedAbilities === null) {
            return $this->parseAbilityList();
        }

        if ($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::STAR)) {
            /* A name followed by `{` or `,` is someone opening a block here rather
               than naming an ability, and saying so names their actual mistake. */
            throw $this->errorAtCurrent($this->abilityBlockAhead()
                ? self::NESTED_ABILITY_BLOCK
                : 'A clause inside an ability block may not name abilities; the block header already names them.');
        }

        return $impliedAbilities;
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
     * Parse an ability check: `can(<ability>)`, or
     * `can(<ability> for <handle> [as <alias>] [with <map>])`.
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

        /* No `for`, no boundary: the reference stays on this schema and this row,
           so there is no handle to read. A `with` map is still read, so that
           handing context to a reference that crosses nothing is answered by
           validation, where the reason can be given, rather than by a parse error
           about a missing keyword. */
        if (! $this->check(TokenType::FOR)) {
            $contextMap = [];

            if ($this->check(TokenType::WITH)) {
                $this->advance();
                $contextMap = $this->parseWithMap();
            }

            $this->expect(TokenType::RPAREN, "Expected 'for' or ')' after the ability name in 'can(...)'.");

            return new CrossSchemaCanNode(null, $ability, contextMap: $contextMap);
        }

        $this->advance(); // consume 'for'

        [$schemaKey, $isRowBound, $boundKey, $alias] = $this->parseHandle();

        $contextMap = [];

        if ($this->check(TokenType::WITH)) {
            $this->advance();
            $contextMap = $this->parseWithMap();
        }

        $this->expect(TokenType::RPAREN, "Expected ')' to close 'can(...)'.");

        return new CrossSchemaCanNode($schemaKey, $ability, $isRowBound, $boundKey, $contextMap, $alias);
    }

    /**
     * Parse a cross-schema condition check:
     * `check(<predicate> for <handle> [as <alias>] [with <map>])`.
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

        [$schemaKey, $isRowBound, $boundKey, $alias] = $this->parseHandle();

        $contextMap = [];

        if ($this->check(TokenType::WITH)) {
            $this->advance();
            $contextMap = $this->parseWithMap();
        }

        $this->expect(TokenType::RPAREN, "Expected ')' to close 'check(...)'.");

        return new CrossSchemaConditionNode($schemaKey, $predicate, $isRowBound, $boundKey, $contextMap, $alias);
    }

    /**
     * Parse a cross-schema handle: a schema name with an optional row selector
     * `schema(<arg>, …)` and an optional `as <alias>`. The selector's absence
     * marks an unbound (no-row) handle.
     *
     * The selector's arguments are bound positionally to the target schema's row
     * key, exactly as a condition's arguments are bound to its parameters — and
     * they are parsed the same way, {@see parseCondition} included, so
     * `schema()` parses to an empty list rather than being a syntax error. How
     * many the target's key actually requires is not knowable here, so arity is
     * left to {@see \Warrant\DSL\Parsing\Validation\RuleSetValidator}, along
     * with the rest of the handle's coherence — including whether the alias is
     * allowed, since an unbound handle selects nothing to name.
     *
     * @return array{0: string, 1: bool, 2: array<int, mixed>, 3: ?string} [schemaKey, isRowBound, boundKey, alias]
     */
    private function parseHandle(): array
    {
        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('a schema name');
        }

        $schemaKey = $this->advance()->lexeme;

        $isRowBound = false;
        $boundKey = [];
        $alias = null;

        if ($this->check(TokenType::LPAREN)) {
            $this->advance();
            $isRowBound = true;

            if (! $this->check(TokenType::RPAREN)) {
                $boundKey[] = $this->parseArgument();

                while ($this->check(TokenType::COMMA)) {
                    $this->advance();
                    $boundKey[] = $this->parseArgument();
                }
            }

            $this->expect(TokenType::RPAREN, "Expected ')' to close the row selector.");
        }

        if ($this->check(TokenType::AS)) {
            $this->advance();

            if (! $this->check(TokenType::IDENTIFIER)) {
                throw $this->nameError("an alias name after 'as'");
            }

            $alias = $this->advance()->lexeme;
        }

        return [$schemaKey, $isRowBound, $boundKey, $alias];
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
            TokenType::COLUMN_REF => $this->parseColumnRef(),
            TokenType::SQL_REF => $this->parseSqlRef(),
            default => throw $this->errorAtCurrent(
                'Expected an argument: a literal, a binding (:name or ?), @context <key>, '
                    .'@column <column> or @column <name>.<column>, or @sql "<sql>".'
            ),
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

    /**
     * Parse a `@column <column>` or `@column <name>.<column>` reference into a
     * symbolic {@see ColumnRef}. Like {@see parseContextRef} it bypasses
     * {@see BindingState} — it is neither a named nor a positional binding — and
     * stays symbolic until {@see \Warrant\DSL\Compiling\RuleSetCompiler} resolves
     * the frame it names to a table and quotes it through the grammar.
     *
     * One name is the column, on whatever rows the enclosing rule is already
     * about — which is most references, and the form that goes on meaning the
     * right thing however the rule is reached. Two name a frame and then the
     * column, for where a rule can see more than one: a `check(...)` predicate
     * spanning its target and its caller.
     */
    private function parseColumnRef(): ColumnRef
    {
        $this->advance(); // consume '@column'

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->errorAtCurrent("Expected a column name after '@column'.");
        }

        $first = $this->advance()->lexeme;

        if (! $this->check(TokenType::DOT)) {
            return new ColumnRef(null, $first);
        }

        $this->advance(); // consume '.'

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->errorAtCurrent("Expected a column name after '@column {$first}.'.");
        }

        return new ColumnRef($first, $this->advance()->lexeme);
    }

    /**
     * Parse a `@sql "<sql>"` reference into a symbolic {@see SqlRef}. The body is
     * either a quoted string literal (single or double quotes) or a `:name` / `?`
     * binding that resolves to a string — bindings resolve to their value at parse
     * time, so `@sql :q` with `q => 'select 1'` is identical to `@sql "select 1"`.
     * The resulting {@see SqlRef} stays symbolic until
     * {@see \Warrant\DSL\Compiling\RuleSetCompiler} resolves it to a parenthesized
     * raw SQL expression.
     */
    private function parseSqlRef(): SqlRef
    {
        $this->advance(); // consume '@sql'

        $token = $this->peek();

        $sql = match ($token->type) {
            TokenType::STRING => $this->advance()->value,
            TokenType::NAMED_BINDING => $this->bindings->resolveNamed($this->advance()),
            TokenType::POSITIONAL => $this->bindings->resolvePositional($this->advance()),
            default => throw $this->errorAtCurrent(
                'Expected a quoted SQL string or a binding (:name or ?) after \'@sql\'.'
            ),
        };

        if (! is_string($sql)) {
            throw WarrantSyntaxException::at(
                sprintf('An @sql binding must resolve to a string, got %s.', get_debug_type($sql)),
                $this->source,
                $token,
            );
        }

        return new SqlRef($sql);
    }

    // -- token helpers --------------------------------------------------------

    private function peek(): Token
    {
        return $this->tokens[$this->index];
    }

    /**
     * The token $distance places past the current one, clamped to the EOF token
     * that always terminates the stream.
     */
    private function peekAhead(int $distance = 1): Token
    {
        return $this->tokens[min($this->index + $distance, count($this->tokens) - 1)];
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
            TokenType::AS,
        ], true) && ctype_alpha($token->lexeme);
    }
}
