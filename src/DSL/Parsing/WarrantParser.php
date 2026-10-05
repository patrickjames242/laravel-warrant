<?php

namespace Warrant\DSL\Parsing;

use Closure;
use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

/**
 * Recursive-descent parser for Warrant rule syntax. Bindings are resolved inline
 * as the tree is built, so the resulting nodes hold only concrete values.
 *
 * Every source parses through {@see parse()} to a {@see WarrantSyntax}, whose
 * children say which form the source took; nothing about the text needs to be
 * known before it is read.
 *
 * Grammar:
 *   syntax   := scoped | entries | expr | ε
 *   scoped   := header body                            -- one bare `for` body, to end of input
 *             | ( header '{' body '}' )+               -- any number, each braced
 *              -- the bare form has no end of its own short of the input's, so a
 *                 second rule set needs braces, and so does the first
 *   header   := 'for' IDENTIFIER
 *   body     := ruleset | expr                         -- a RuleSetNode, or a SchemaConditionNode
 *   entries  := ruleset                                -- headless, at least one entry
 *   ruleset  := ( clauses | 'if' expr clause+ | ability_block | include )*
 *              -- consecutive `they` clauses merge into one unconditional rule
 *   ability_block := 'can' 'they' ability (',' ability)* '{' ruleset '}'
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
 *                 Each `they cannot ...` clause becomes one CannotClauseNode on the
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
     * Whether the body being read is a rule template's rather than an ability
     * block's. Both are headless and so travel the same path, but they reject the
     * same mistakes for different reasons, and an author told about a block they
     * never opened goes looking for one.
     *
     * A field rather than a parameter because the two never nest inside one parse:
     * a template's body may not open a block, and a block may not hold a body.
     */
    private bool $inTemplateBody = false;

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
     * Parse Warrant syntax of any form, resolving $bindings inline.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parse(string $source, array $bindings = []): WarrantSyntax
    {
        $parser = new self($source, $bindings);

        $children = match (true) {
            $parser->check(TokenType::EOF) => [],
            $parser->check(TokenType::FOR) => $parser->parseScopedBodies(),
            $parser->check(TokenType::LBRACE) => throw $parser->errorAtCurrent(
                'A `{ ... }` block needs a `for <schema>` header before it.'
            ),
            $parser->ruleAhead() => $parser->parseHeadlessEntries(),
            default => [$parser->parseBareExpression()],
        };

        $parser->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
        $parser->bindings->finalize($parser->peek());

        return new WarrantSyntax($children);
    }

    /**
     * Parse a rule template's body: headless rules and includes, for the
     * `@include` expanding it to give its abilities.
     *
     * The body is read exactly as an ability block's is, and the same three rules
     * follow from that: a clause may not name abilities, no ability block may be
     * opened inside it, and an `@include` in the body names no abilities either —
     * the outer `@include` gives them to it, which is how a template that includes
     * another resolves.
     *
     * @param array<int|string, mixed> $bindings
     * @return list<WarrantRuleNode|IncludeInvocationNode>
     */
    public static function parseTemplateBody(string $source, array $bindings = []): array
    {
        $parser = new self($source, $bindings);
        $parser->inTemplateBody = true;

        /** @var list<WarrantRuleNode|IncludeInvocationNode> $entries */
        $entries = $parser->parseRules(headless: true);

        $parser->expect(TokenType::EOF, 'Unexpected token; expected end of input.');
        $parser->bindings->finalize($parser->peek());

        return $entries;
    }

    /**
     * Whether a rule entry starts here. These four are the only tokens that can
     * open one, and none of them can open an expression: `can(...)` is told apart
     * from a block's `can they` by the token after it.
     */
    private function ruleAhead(): bool
    {
        return $this->check(TokenType::THEY)
            || $this->check(TokenType::IF)
            || $this->check(TokenType::INCLUDE_REF)
            || $this->abilityBlockAhead();
    }

    /**
     * Parse rule entries written with no `for` header, which run to the end of
     * the input.
     *
     * @return list<IRuleEntryNode>
     */
    private function parseHeadlessEntries(): array
    {
        $entries = $this->parseRules();

        if ($this->check(TokenType::FOR)) {
            throw $this->errorAtCurrent(
                'Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own.'
            );
        }

        return $entries;
    }

    /**
     * Parse one bare `for <schema>` body running to the end of input, or a run of
     * braced `for <schema> { ... }` blocks. Blocks are returned in source order
     * and are not merged; {@see WarrantSyntax::forSchema()} folds same-schema
     * rule sets together.
     *
     * @return list<ISchemaScopedNode>
     */
    private function parseScopedBodies(): array
    {
        $schemaKey = $this->parseHeader();

        if (! $this->check(TokenType::LBRACE)) {
            $body = $this->parseScopedBody($schemaKey);

            if ($this->check(TokenType::FOR) || $this->check(TokenType::LBRACE)) {
                throw $this->errorAtCurrent(
                    'Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.'
                );
            }

            return [$body];
        }

        $bodies = [$this->parseBracedScopedBody($schemaKey)];

        while (! $this->check(TokenType::EOF)) {
            if (! $this->check(TokenType::FOR)) {
                throw $this->errorAtCurrent(
                    'Expected `for <schema> { ... }`; every rule set beside a braced one needs a `for` header and braces.'
                );
            }

            $bodies[] = $this->parseBracedScopedBody($this->parseHeader());
        }

        return $bodies;
    }

    /**
     * Parse the body of a `for <schema>` header: a rule set when a rule starts
     * here or the body is empty, and otherwise one condition expression scoped
     * to the header's schema.
     */
    private function parseScopedBody(string $schemaKey): ISchemaScopedNode
    {
        $atEnd = $this->check(TokenType::EOF) || $this->check(TokenType::RBRACE);

        return $atEnd || $this->ruleAhead()
            ? new RuleSetNode($schemaKey, $this->parseRules())
            : new SchemaConditionNode($schemaKey, $this->parseBareExpression());
    }

    /**
     * Parse a braced `{ <body> }` after a `for <schema>` header.
     */
    private function parseBracedScopedBody(string $schemaKey): ISchemaScopedNode
    {
        $this->expect(TokenType::LBRACE, "Expected '{' to open the rule set body.");
        $body = $this->parseScopedBody($schemaKey);
        $this->expect(TokenType::RBRACE, "Expected '}' to close the rule set body.");

        return $body;
    }

    /**
     * Parse a condition expression standing as a whole body, where nothing
     * started a rule.
     */
    private function parseBareExpression(): IBooleanExpressionNode
    {
        $this->assertNoBareBlockHeader();

        return $this->parseExpression();
    }

    /**
     * Parse a `for <schema>` header, returning the schema key.
     */
    private function parseHeader(): string
    {
        $this->expect(TokenType::FOR, 'Expected a `for <schema>` header.');

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('a schema name after `for`');
        }

        return $this->advance()->lexeme;
    }

    /**
     * Parse a rule body: unconditional clauses, `if` rules, ability blocks and
     * `@include` directives, in any order.
     *
     * What comes back is one list in source order. An include keeps its place
     * among the rules because expansion splices the template's rules in where it
     * was written, so the position is part of what the include means; a block
     * keeps its place as an {@see AbilityBlockNode} holding its own entries.
     *
     * @param bool $headless Whether this body is the inside of an ability block
     *   or a template body, where clauses and includes name no abilities because
     *   something outside names them. It is also what makes a further block a
     *   nesting error.
     * @return list<IRuleEntryNode>
     */
    private function parseRules(bool $headless = false): array
    {
        $entries = [];

        while (true) {
            /* `they` clauses with no `if` form one unconditional rule.
               parseTheyCanCannotClauses() absorbs every consecutive `they`, so this is
               reachable only at the start of a body or after an ability block. */
            if ($this->check(TokenType::THEY)) {
                $entries[] = $this->parseTheyCanCannotClauses(null, $headless);

                continue;
            }

            if ($this->check(TokenType::INCLUDE_REF)) {
                $entries[] = $this->parseInclude($headless);

                continue;
            }

            // Each `if` starts a new conditional rule.
            if ($this->check(TokenType::IF)) {
                $this->advance();
                $conditions = $this->parseExpression();
                $entries[] = $this->parseTheyCanCannotClauses($conditions, $headless);

                continue;
            }

            if ($this->abilityBlockAhead()) {
                if ($headless) {
                    throw $this->errorAtCurrent($this->nestedBlockError());
                }

                $entries[] = $this->parseAbilityBlock();

                continue;
            }

            $this->assertNoBareBlockHeader();

            return $entries;
        }
    }

    /**
     * Parse an `@include`: the template to expand, its arguments, and the
     * abilities the clauses it expands to will take.
     *
     * Outside a block the reference names those abilities with its own `for`
     * list. Inside a block or a template body it is headless, and a `for` list is
     * rejected for the reason a clause's ability list is: the block header or the
     * outer `@include` is the one place the ability is said.
     */
    private function parseInclude(bool $headless): IncludeInvocationNode
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

        if ($headless) {
            if ($this->check(TokenType::FOR)) {
                throw $this->errorAtCurrent($this->inTemplateBody
                    ? "An @include inside a rule template's body may not name abilities; the @include that expands it names them."
                    : 'An @include inside an ability block may not name abilities; the block header already names them.');
            }

            return new IncludeInvocationNode($templateKey, $arguments);
        }

        if (! $this->check(TokenType::FOR)) {
            throw $this->errorAtCurrent(
                'An @include outside an ability block must name the abilities it applies to, as '
                    .'`@include <template> for <ability>, ...`.'
            );
        }

        $this->advance();

        return new IncludeInvocationNode($templateKey, $arguments, $this->parseAbilityList());
    }

    /**
     * Parse an ability block: an ability list, then a braced body whose clauses
     * take those abilities instead of naming any.
     *
     * The block is grouping and nothing more. Its entries are headless, as the
     * source writes them, and the header alone says which abilities they take;
     * {@see AbilityBlockNode::expand()} applies it.
     */
    private function parseAbilityBlock(): AbilityBlockNode
    {
        $this->advance(); // consume 'can'
        $this->advance(); // consume 'they'

        $abilities = $this->parseAbilityList();

        $this->expect(TokenType::LBRACE, "Expected '{' to open the ability block body.");
        /** @var list<WarrantRuleNode|IncludeInvocationNode> $entries */
        $entries = $this->parseRules(headless: true);
        $this->expect(TokenType::RBRACE, "Expected '}' to close the ability block body.");

        return new AbilityBlockNode($abilities, $entries);
    }

    /**
     * Reject an ability list sitting where a rule or a condition should start.
     * That is the block header written without its `can they`, and neither
     * "expected end of input" nor an expression error would name the mistake.
     */
    private function assertNoBareBlockHeader(): void
    {
        if (($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::STAR))
            && in_array($this->peekAhead()->type, [TokenType::LBRACE, TokenType::COMMA], true)) {
            throw $this->errorAtCurrent(
                'An ability block header is written `can they <ability>, ... { ... }`.'
            );
        }
    }

    /**
     * Whether an ability block starts here: the `can they` that heads one.
     *
     * Two tokens settle it, and nothing deeper is needed. Where a rule may begin,
     * only `if` and `they` can open one, so a `can` here starts a block and
     * nothing else — `can(...)` is an expression and is reachable only after
     * `if`, and the clause keyword is reached as `they can`, the other way round.
     */
    private function abilityBlockAhead(): bool
    {
        return $this->check(TokenType::CAN) && $this->peekAhead()->type === TokenType::THEY;
    }

    /**
     * Parse the `they can/cannot` clauses that share one condition (the clauses
     * after an `if`, or the leading clauses with none) into a single rule. Each
     * `they can <abilities>` clause becomes one {@see CanClauseNode}, and each
     * `they cannot <abilities> [because <msg>]` clause one {@see CannotClauseNode},
     * so distinct clauses keep distinct messages on the same rule.
     */
    private function parseTheyCanCannotClauses(
        ?IBooleanExpressionNode $conditions,
        bool $headless,
    ): WarrantRuleNode {
        $canClauses = [];
        $cannotClauses = [];
        $sawClause = false;

        while ($this->check(TokenType::THEY)) {
            $this->advance();
            $sawClause = true;

            if ($this->check(TokenType::CAN)) {
                $this->advance();
                $canClauses[] = new CanClauseNode($this->parseClauseAbilities($headless));

                // A `because` message only ever surfaces for a matching `cannot`;
                // hanging one off a `can` clause can never fire, so reject it here.
                if ($this->check(TokenType::BECAUSE)) {
                    throw $this->errorAtCurrent(
                        "'because' may only follow a 'they cannot ...' clause, not 'they can ...'."
                    );
                }
            } elseif ($this->check(TokenType::CANNOT)) {
                $this->advance();
                $abilities = $this->parseClauseAbilities($headless);

                $message = null;

                if ($this->check(TokenType::BECAUSE)) {
                    $this->advance();
                    $message = $this->parseDenialMessage();
                }

                $cannotClauses[] = new CannotClauseNode($abilities, $message);
            } else {
                throw $this->errorAtCurrent("Expected 'can' or 'cannot' after 'they'.");
            }
        }

        if (! $sawClause) {
            throw $this->errorAtCurrent("Expected at least one 'they can ...' or 'they cannot ...' clause.");
        }

        return new WarrantRuleNode($conditions, $canClauses, $cannotClauses);
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
     * The abilities one clause names: its list, or none in a headless body.
     *
     * Inside a block the list is not optional but forbidden. The header is the one
     * place the ability is said, so every clause in the block has a single reading
     * and the header stays a complete account of what the block is about.
     *
     * @return list<string>
     */
    private function parseClauseAbilities(bool $headless): array
    {
        if (! $headless) {
            return $this->parseAbilityList();
        }

        if ($this->check(TokenType::IDENTIFIER) || $this->check(TokenType::STAR)) {
            throw $this->errorAtCurrent($this->namedAbilitiesError());
        }

        return [];
    }

    /**
     * Raised where a clause names abilities that the enclosing construct already
     * named for it.
     */
    private function namedAbilitiesError(): string
    {
        return $this->inTemplateBody
            ? "A rule template's body may not name abilities; the @include that expands it names them."
            : 'A clause inside an ability block may not name abilities; the block header already names them.';
    }

    /**
     * Raised where a `can they ...` block is opened inside a body that is already
     * headless, and so has the abilities the block would name.
     */
    private function nestedBlockError(): string
    {
        return $this->inTemplateBody
            ? "A rule template's body may not open an ability block; the @include that expands it names the abilities."
            : 'An ability block may not contain another; the enclosing block already names the abilities.';
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
     * keyword in `they can ...` is consumed by {@see parseTheyCanCannotClauses()} and never
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
