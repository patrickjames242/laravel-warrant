/**
 * Tree-sitter grammar for the Warrant authorization rule DSL.
 *
 * Ported from the PHP implementation, which remains the source of truth:
 *   - lexical rules  -> src/DSL/Lexing/Lexer.php (and TokenType.php)
 *   - structure      -> the grammar docblock atop src/DSL/Parsing/WarrantParser.php
 *
 * If the DSL grows new tokens or forms, change them there first, then mirror
 * them here and in editors/vscode/syntaxes/warrant.tmLanguage.json.
 *
 * This grammar is deliberately a little more permissive than the PHP parser:
 * it accepts shapes the parser rejects for semantic reasons (for example
 * mixing a bare `for <schema>` header with braced blocks, or a `because` on a
 * `can` clause). Highlighting a technically-invalid file sensibly is better
 * than collapsing it into an ERROR node, and real diagnostics are the language
 * server's job -- see specs/language-server.md.
 */

module.exports = grammar({
  name: 'warrant',

  // Enables keyword extraction, so `iffy` lexes as one identifier rather than
  // the keyword `if` followed by `fy`.
  word: $ => $.identifier,

  extras: $ => [/\s/, $.comment],

  supertypes: $ => [$._expression, $._argument],

  rules: {
    // A file is either a sequence of `for <schema> { ... }` blocks, or a bare
    // rule body optionally preceded by a `for <schema>` header. Both entry
    // shapes exist on the PHP side (parseGroup vs parseSingleRuleSet), and a
    // .warrant file may be any of them.
    source_file: $ => seq(
      optional($.schema_header),
      repeat(choice($.schema_block, $.rule)),
    ),

    schema_header: $ => seq(
      'for',
      field('schema', $.identifier),
    ),

    schema_block: $ => seq(
      $.schema_header,
      field('body', $.block_body),
    ),

    block_body: $ => seq('{', repeat($.rule), '}'),

    // One rule: an optional `if <expression>` guard, then the `they can` /
    // `they cannot` clauses that share it. Clauses with no `if` form a single
    // unconditional rule; each `if` begins a new one.
    // Right-associative so consecutive clauses are absorbed greedily into the
    // rule already being built, mirroring parseClausesInto()'s
    // `while ($this->check(THEY))` loop -- every `they` clause up to the next
    // `if` shares that rule's condition. Left associativity would instead end
    // the rule after each clause, wrongly making a trailing `they cannot ...`
    // an unconditional rule of its own.
    rule: $ => prec.right(seq(
      optional($.condition_clause),
      repeat1($._clause),
    )),

    condition_clause: $ => seq(
      'if',
      field('condition', $._expression),
    ),

    _clause: $ => choice($.can_clause, $.cannot_clause),

    can_clause: $ => seq(
      'they',
      'can',
      field('abilities', $.ability_list),
    ),

    cannot_clause: $ => seq(
      'they',
      'cannot',
      field('abilities', $.ability_list),
      optional(field('denial', $.because_clause)),
    ),

    // `because` attaches a denial message. The message is fixed at parse time,
    // so it is a literal string or a binding -- never an @context reference.
    because_clause: $ => seq(
      'because',
      field('message', choice($.string, $.named_binding, $.positional)),
    ),

    ability_list: $ => seq(
      $.ability,
      repeat(seq(',', $.ability)),
    ),

    ability: $ => choice($.identifier, $.wildcard),

    wildcard: _ => '*',

    // -- expressions ---------------------------------------------------------

    _expression: $ => choice(
      $.or_expression,
      $.and_expression,
      $.not_expression,
      $.parenthesized_expression,
      $.can_expression,
      $.check_expression,
      $.condition,
    ),

    or_expression: $ => prec.left(1, seq(
      field('left', $._expression),
      'or',
      field('right', $._expression),
    )),

    and_expression: $ => prec.left(2, seq(
      field('left', $._expression),
      'and',
      field('right', $._expression),
    )),

    not_expression: $ => prec.right(3, seq(
      choice('not', '!'),
      field('operand', $._expression),
    )),

    parenthesized_expression: $ => seq('(', $._expression, ')'),

    // A cross-schema ability check: can(<ability> for <handle> [with <map>]).
    can_expression: $ => seq(
      'can',
      '(',
      field('ability', $.identifier),
      'for',
      field('target', $.handle),
      optional(field('context', $.with_clause)),
      ')',
    ),

    // A cross-schema condition check: check(<predicate> for <handle> [with <map>]).
    // The predicate is a full boolean tree of the *target* schema's conditions.
    check_expression: $ => seq(
      'check',
      '(',
      field('predicate', $._expression),
      'for',
      field('target', $.handle),
      optional(field('context', $.with_clause)),
      ')',
    ),

    // A schema name, optionally row-bound by a single selector argument.
    // Without the selector the handle is unbound (no row involved).
    handle: $ => seq(
      field('schema', $.identifier),
      optional(seq('(', field('row', $._argument), ')')),
    ),

    with_clause: $ => seq(
      'with',
      $.with_entry,
      repeat(seq(',', $.with_entry)),
    ),

    with_entry: $ => seq(
      field('key', $.identifier),
      '=',
      field('value', $._argument),
    ),

    // A local condition on the current schema, with optional arguments.
    condition: $ => seq(
      field('name', $.identifier),
      optional(field('arguments', $.argument_list)),
    ),

    argument_list: $ => seq(
      '(',
      optional(seq($._argument, repeat(seq(',', $._argument)))),
      ')',
    ),

    // -- arguments and literals ----------------------------------------------

    _argument: $ => choice(
      $.string,
      $.float,
      $.integer,
      $.boolean,
      $.null,
      $.named_binding,
      $.positional,
      $.context_ref,
      $.column_ref,
      $.sql_ref,
    ),

    // A check-time context value, resolved per authorization check.
    context_ref: $ => seq(
      '@context',
      field('key', $.identifier),
    ),

    // A schema-qualified database column, resolved to a real table at compile time.
    column_ref: $ => seq(
      '@column',
      field('schema', $.identifier),
      '.',
      field('column', $.identifier),
    ),

    // An arbitrary SQL fragment, as a literal or a binding resolving to a string.
    sql_ref: $ => seq(
      '@sql',
      field('sql', choice($.string, $.named_binding, $.positional)),
    ),

    // -- terminals -----------------------------------------------------------

    // Only \' \" and \\ are legal escapes; the closing quote must match the opener.
    //
    // The body is one addressable `string_content` node spanning everything
    // between the quotes, which is what lets injections.scm hand an @sql body
    // to the SQL grammar. The double-quoted variant is hidden and aliased, so
    // both quote styles produce the same node name.
    string: $ => choice(
      seq("'", optional($.string_content), "'"),
      seq('"', optional(alias($._double_quoted_content, $.string_content)), '"'),
    ),

    string_content: $ => repeat1(choice($.escape_sequence, token.immediate(/[^'\\]+/))),

    _double_quoted_content: $ => repeat1(choice($.escape_sequence, token.immediate(/[^"\\]+/))),

    escape_sequence: _ => token.immediate(/\\['"\\]/),

    float: _ => /-?\d+\.\d+/,

    integer: _ => /-?\d+/,

    boolean: _ => choice('true', 'false'),

    null: _ => 'null',

    named_binding: _ => /:[A-Za-z_][A-Za-z0-9_-]*/,

    positional: _ => '?',

    // A letter or underscore, then letters, digits, underscores or dashes.
    identifier: _ => /[A-Za-z_][A-Za-z0-9_-]*/,

    // `#` to end of line. A `#` inside a string is literal, since comments are
    // only recognised between tokens.
    comment: _ => token(seq('#', /[^\n]*/)),
  },
});
