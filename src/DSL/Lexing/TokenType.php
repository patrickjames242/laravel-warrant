<?php

namespace Warrant\DSL\Lexing;

enum TokenType
{
    // Keywords / rule structure.
    case IF;
    case THEY;
    case CAN;
    case CANNOT;
    case BECAUSE; // denial message: they cannot <abilities> because '<message>'
    case CHECK; // cross-schema condition builtin: check(<predicate> for <handle>)
    case FOR;  // cross-schema handle: can(<ability> for <handle>)
    case WITH; // cross-schema context map: ... with <key> = <value>

    // Boolean operators.
    case AND;
    case OR;
    case NOT; // covers both `not` and `!`

    // Punctuation.
    case LPAREN;
    case RPAREN;
    case LBRACE; // `{` opens a `for <schema> { ... }` rule-set block
    case RBRACE; // `}` closes a rule-set block
    case COMMA;
    case STAR;   // wildcard ability `*`
    case EQUALS; // `=` in a cross-schema `with` map
    case DOT;    // `.` separating a schema key from a column in `@column schema.column`

    // Bindings.
    case NAMED_BINDING;  // :name
    case POSITIONAL;     // ?
    case CONTEXT_REF;    // @context (followed by the key identifier)
    case COLUMN_REF;     // @column (followed by `<schema> . <column>`)
    case SQL_REF;        // @sql (followed by a quoted string literal)

    // Names and literals.
    case IDENTIFIER; // condition or ability name
    case STRING;
    case INT;
    case FLOAT;
    case BOOL;
    case NULL;

    case EOF;
}
