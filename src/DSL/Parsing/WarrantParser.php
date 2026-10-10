<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Grammar\ParseSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ParsingState;
use Warrant\DSL\Parsing\Positions\SourceMap;

/**
 * Parses Warrant rule syntax. Bindings are resolved inline as the tree is
 * built, so the resulting nodes hold only concrete values.
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
 *   ruleset  := ( clauses | 'if' expr clause+ | ability_block | include )*
 *              -- consecutive `they` clauses merge into one unconditional rule
 *   ability_block := 'can' 'they' ability (',' ability)* '{' ruleset '}'
 *              -- the header says the abilities once, so clauses inside are
 *                 generic and may not name their own; a block never contains
 *                 another
 *   include  := '@include' IDENTIFIER ( '(' (arg (',' arg)*)? ')' )?
 *                          ( 'for' ability (',' ability)* )?
 *              -- expands a schema's rule template. The `for` list is required
 *                 in a `for` body and forbidden inside an ability block, where the
 *                 header already names the abilities
 *   clause   := 'they' ( 'can' ability (',' ability)*
 *                      | 'cannot' ability (',' ability)* ( 'because' message )? )
 *              -- `because` attaches a denial message; valid only after `cannot`.
 *                 Each `they cannot ...` clause becomes one CannotClauseNode on the
 *                 rule, so distinct clauses keep distinct messages.
 *                 Inside an ability block the ability list is omitted entirely.
 *              -- in rules with no `for` header, clauses and includes either all
 *                 name their abilities or all leave them off, as a rule template's
 *                 body does; the first one decides
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
     * Parse Warrant syntax of any form, resolving $bindings inline.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parse(string $source, array $bindings = []): WarrantSyntax
    {
        return Parser::run(new ParseSyntax, ParsingState::forSource($source, $bindings));
    }

    /**
     * Parse Warrant syntax of any form, as {@see parse()} does, and record where
     * every node of the tree was written. The root {@see WarrantSyntax} stands
     * for the whole text and is not recorded.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function parseWithPositions(string $source, array $bindings = []): ParseResult
    {
        $state = ParsingState::forSource($source, $bindings);
        $syntax = Parser::run(new ParseSyntax, $state);
        $state->commitTo($positions = new SourceMap);

        return new ParseResult($syntax, $positions);
    }
}
