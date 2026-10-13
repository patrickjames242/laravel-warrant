<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Grammar\ParseSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ParsingState;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\DSL\Parsing\Validation\WarrantSyntaxValidator;
use Warrant\DSL\SchemaCatalog;

/**
 * Parses Warrant rule syntax. The parse reads each `:name` and `?` as a
 * {@see \Warrant\DSL\Parsing\ASTNodes\BindingNode} and puts no value in its
 * place: that is {@see WarrantSyntax::resolve()}'s, once the text has been
 * validated as it was written.
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
     * Parse Warrant syntax of any form as it was written, its bindings left as
     * the placeholders they are, and record where every node of the tree was
     * written. The root {@see WarrantSyntax} stands for the whole text and is
     * not recorded.
     */
    public static function parse(string $source): WarrantSyntax
    {
        $state = ParsingState::forSource($source);
        $syntax = Parser::run(new ParseSyntax, $state);
        $state->commitTo($positions = new SourceMap);

        return new WarrantSyntax($syntax->children, $source, $positions);
    }

    /**
     * Read Warrant syntax of any form as an editor has it — mid-edit, and with
     * no values for its placeholders — reporting syntax errors as diagnostics
     * instead of throwing them.
     *
     * The tree is the one {@see parse()} reads, its bindings left
     * as placeholders. Every lexical error is reported, and the read goes on
     * past it. So does every grammar error between or inside rule entries and
     * `for` blocks. A missing bracket or keyword is read as though it were
     * there, a missing condition as a {@see \Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode},
     * a missing argument as a {@see \Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode},
     * a missing name as {@see \Warrant\DSL\Parsing\ASTNodes\INode::MISSING_NAME},
     * and missing clauses, abilities, an alias or a denial message as none, so
     * the entry around the mistake is kept. Text between entries that starts
     * none is skipped to where the next one can start. After a mistake, no other
     * is reported until three tokens have been read, since the first often leads
     * to more.
     * A `{` with no `for <schema>` header is read as a block whose header names
     * no schema, and a block written where it should not be is read all the
     * same, so every grammar error is repaired and the read reaches the end of
     * the text. The first grammar error reported is the one parse() throws.
     *
     * The text is validated too, and every rule of the language it breaks is
     * reported after the syntax errors. Given a $catalog, each `for` body is
     * checked against the schema its header names, and rules with no header
     * against the schema $schemaKey names; without one, only the rules of the
     * language that need no schema are checked.
     */
    public static function analyze(string $source, ?SchemaCatalog $catalog = null, ?string $schemaKey = null): AnalysisResult
    {
        $state = ParsingState::forAnalysis($source);
        $positions = new SourceMap;

        try {
            $children = Parser::run(new ParseSyntax, $state)->children;
            $state->commitTo($positions);
        } catch (WarrantSyntaxException $error) {
            /* A tolerant read reports every grammar error rather than throwing it.
               One that were thrown would end the read here, with an empty tree,
               rather than reach the editor. */
            $state->diagnose($error);
            $children = [];
        }

        $syntax = new WarrantSyntax($children, $source, $positions);

        $headerlessSchema = $schemaKey === null ? null : $catalog?->find($schemaKey);
        $validator = new WarrantSyntaxValidator($headerlessSchema, $headerlessSchema === null ? null : $schemaKey, $catalog);

        return new AnalysisResult($syntax, [...$state->diagnostics, ...$validator->diagnose($syntax)], $state->scannedTokens);
    }
}
