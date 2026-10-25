<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Grammar\ParseSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ParsingState;

/**
 * Parses Warrant rule syntax, and only parses it: what it reads is validated
 * apart, by {@see \Warrant\DSL\Parsing\ASTNodes\ValidSyntax}. The parse reads
 * each `:name` and `?` as a {@see \Warrant\DSL\Parsing\ASTNodes\BindingNode},
 * which finds its value in the source it was read from, and checks none of the
 * values: whether each has one, and whether it fits, is the validator's.
 *
 * Every node read is placed in one {@see SyntaxSource}: the text as it was
 * handed over, with what produced it.
 *
 * Every source parses through {@see parse()} to a {@see WarrantSyntax}, whose
 * children say which form the source took; nothing about the text needs to be
 * known before it is read. The grammar is read by the parsers in
 * {@see \Warrant\DSL\Parsing\Grammar}, starting from {@see ParseSyntax}.
 *
 * Grammar:
 *   syntax   := scoped | entries | expr | ε
 *   scoped   := header body                            -- one bare `for` body, to end of input
 *             | ( header '{' body '}' )+               -- any number, each braced
 *              -- the bare form has no end of its own short of the input's, so a
 *                 second rule set needs braces, and so does the first
 *   header   := 'for' IDENTIFIER
 *   body     := ruleset | expr                         -- a RuleSetNode, or a SchemaConditionNode
 *   entries  := ruleset                                -- unscoped, at least one entry
 *   ruleset  := ( can_or_cannot_clause+ | 'if' expr can_or_cannot_clause+ | ability_block | when_block | include )*
 *              -- consecutive can/cannot clauses merge into one unconditional rule
 *   ability_block := 'can' 'they' ability (',' ability)* '{' ruleset '}'
 *              -- the header says the abilities once, so can/cannot clauses
 *                 inside are generic and may not name their own; a block never contains
 *                 another
 *   when_block := 'when' expr '{' ruleset '}' ( 'otherwise' '{' ruleset '}' )?
 *              -- every rule the body expands to takes the condition, and every
 *                 rule the `otherwise` body expands to takes its negation. Both
 *                 bodies are always braced, and `otherwise when` is an error
 *   include  := '@include' IDENTIFIER ( '(' (arg (',' arg)*)? ')' )?
 *                          ( 'for' ability (',' ability)* )?
 *              -- expands a schema's rule template. The `for` list is required
 *                 in a `for` body and forbidden inside an ability block, where the
 *                 header already names the abilities
 *   can_or_cannot_clause := 'they' ( 'can' ability (',' ability)*
 *                                  | 'cannot' ability (',' ability)* ( 'because' message )? )
 *              -- `because` attaches a denial message; valid only after `cannot`.
 *                 Each `they cannot ...` clause becomes one CannotClauseNode on the
 *                 rule, so distinct cannot clauses keep distinct messages.
 *                 Inside an ability block the ability list is omitted entirely.
 *              -- in rules with no `for` header, can/cannot clauses and includes either all
 *                 name their abilities or all leave them off, as a rule template's
 *                 body does; the first one decides
 *   message  := STRING | NAMED_BINDING
 *              -- a string literal, or a binding resolving to a string or closure
 *   ability  := IDENTIFIER | '*' | NAMED_BINDING
 *              -- a binding's value is an ability name, `*`, or a list of them,
 *                 spliced into the list where the binding is written
 *   expr     := or
 *   or       := and ('or' and)*
 *   and      := not ('and' not)*
 *   not      := ('not'|'!') not | primary
 *   primary  := '(' expr ')' | can_expr | check_expr | condition
 *   can_expr := 'can' '(' can_ability ( 'for' handle ( 'with' with_map )? )? ')'
 *             | 'can' can_ability
 *              -- without `for` nothing is crossed: another ability of this
 *                 schema, over the row and context the rule already has. Only
 *                 then may the parentheses be left off, so a `for` after a bare
 *                 `can <ability>` is never its own
 *   can_ability := IDENTIFIER | NAMED_BINDING
 *              -- one ability: a binding's value is a single ability name
 *   check_expr := 'check' '(' expr 'for' handle ( 'with' with_map )? ')'
 *              -- the inner expr is a boolean tree of the target schema's conditions
 *   handle   := IDENTIFIER ( '(' arg ')' )? ( 'as' IDENTIFIER )?
 *              -- no parens: unbound; one arg: row-bound. `as` names the rows this
 *                 reference selects, so a @column can tell them apart from a table
 *                 of the same name further out; only a row-bound handle may take one.
 *   with_map := with_entry (',' with_entry)*
 *   with_entry := IDENTIFIER '=' arg
 *   condition:= IDENTIFIER ( '(' (arg (',' arg)*)? ')' )?
 *   arg      := literal | NAMED_BINDING | context_ref | column_ref
 *   context_ref := '@context' IDENTIFIER
 *   column_ref  := '@column' IDENTIFIER ( '.' IDENTIFIER )?
 *              -- one name is the column, on the rows the rule is already about;
 *                 two are a frame name (a schema key, or a handle alias) and then
 *                 the column
 *
 * @internal
 */
final class WarrantParser
{
    /**
     * Parse Warrant syntax of any form as it was written, its bindings left as
     * the placeholders they are, every node placed where it was written. A
     * string is rule text with no bindings. $producer says what handed the
     * syntax over; with none, nothing has yet.
     */
    public static function parse(string|BoundSyntax $syntax, ?SyntaxProducer $producer = null): WarrantSyntax
    {
        $state = ParsingState::forSource(self::sourceOf($syntax, $producer));

        return new WarrantSyntax(Parser::run(new ParseSyntax, $state), $state->source);
    }

    /**
     * Read Warrant syntax of any form as an editor has it — mid-edit — reporting
     * syntax errors as diagnostics instead of throwing them.
     *
     * The tree is the one {@see parse()} reads, its bindings left
     * as placeholders. Every lexical error is reported, and the read goes on
     * past it. So does every grammar error between or inside rule entries and
     * `for` blocks. A missing bracket or keyword is read as though it were
     * there, a missing condition as a {@see \Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode},
     * a missing argument as a {@see \Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode},
     * a missing name as {@see \Warrant\DSL\Parsing\ASTNodes\INode::MISSING_NAME},
     * and missing can/cannot clauses, abilities, an alias or a denial message as none, so
     * the entry around the mistake is kept. Text between entries that starts
     * none is skipped to where the next one can start. After a mistake, no other
     * is reported until three tokens have been read, since the first often leads
     * to more.
     * A `{` with no `for <schema>` header is read as a block whose header names
     * no schema, and a block written where it should not be is read all the
     * same, so every grammar error is repaired and the read reaches the end of
     * the text. The first grammar error reported is the one parse() throws.
     *
     * Only syntax errors are reported: the tree is validated apart, as any
     * parsed tree is.
     */
    public static function analyze(string|BoundSyntax $syntax, ?SyntaxProducer $producer = null): AnalysisResult
    {
        $state = ParsingState::forAnalysis(self::sourceOf($syntax, $producer));

        try {
            $children = Parser::run(new ParseSyntax, $state);
        } catch (WarrantSyntaxException $error) {
            /* A tolerant read reports every grammar error rather than throwing it.
               One that were thrown would end the read here, with an empty tree,
               rather than reach the editor. */
            $state->diagnose($error);
            $children = [];
        }

        return new AnalysisResult(new WarrantSyntax($children, $state->source), $state->diagnostics, $state->scannedTokens);
    }

    private static function sourceOf(string|BoundSyntax $syntax, ?SyntaxProducer $producer): SyntaxSource
    {
        return new SyntaxSource(
            is_string($syntax) ? new BoundSyntax($syntax) : $syntax,
            $producer,
        );
    }
}
