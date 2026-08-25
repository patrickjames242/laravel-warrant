<?php

namespace Warrant\RuleSyntaxTree\Parsing;

enum TokenType
{
    // Keywords / rule structure.
    case IF;
    case THEY;
    case CAN;
    case CANNOT;
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
    case COMMA;
    case STAR;   // wildcard ability `*`
    case EQUALS; // `=` in a cross-schema `with` map

    // Bindings.
    case NAMED_BINDING;  // :name
    case POSITIONAL;     // ?
    case CONTEXT_REF;    // @context (followed by the key identifier)

    // Names and literals.
    case IDENTIFIER; // condition or ability name
    case STRING;
    case INT;
    case FLOAT;
    case BOOL;
    case NULL;

    case EOF;
}
