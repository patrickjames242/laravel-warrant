---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Grammar reference
description: The complete grammar, keywords, and operators, in one page to search.
sidebar:
  order: 11
---

A page to search rather than read. Concepts are on the pages this one summarizes.

## The grammar

```text
syntax        = scoped | entries | expr | (* empty *) ;

scoped        = header body
              | ( header "{" body "}" )+ ;
                (* the bare form runs to the end of the input, so a second
                   rule set needs braces, and so does the first *)
header        = "for" IDENTIFIER ;
body          = ruleset | expr ;
                (* a rule set, or a condition for that schema *)
entries       = ruleset ;
                (* no header, at least one entry *)

ruleset       = ( clause+ | "if" expr clause+ | ability_block | include )* ;

ability_block = "can" "they" ability ( "," ability )* "{" ruleset "}" ;

include       = "@include" IDENTIFIER [ "(" [ arg { "," arg } ] ")" ]
                           [ "for" ability { "," ability } ] ;
                (* the `for` list is required outside an ability block
                   and forbidden inside one *)

clause        = "they" ( "can" ability ( "," ability )*
                       | "cannot" ability ( "," ability )* ( "because" message )? ) ;
                (* inside an ability block the ability list is omitted *)

ability       = IDENTIFIER | "*" ;
message       = STRING | NAMED_BINDING | POSITIONAL ;

expr          = or ;
or            = and ( "or" and )* ;
and           = not ( "and" not )* ;
not           = ( "not" | "!" ) not | primary ;
primary       = "(" expr ")" | can_ref | check_ref | condition ;

condition     = IDENTIFIER ( "(" ( arg ( "," arg )* )? ")" )? ;
can_ref       = "can" "(" IDENTIFIER ( "for" handle ( "with" with_map )? )? ")" ;
check_ref     = "check" "(" expr "for" handle ( "with" with_map )? ")" ;
handle        = IDENTIFIER ( "(" arg ")" )? ( "as" IDENTIFIER )? ;
with_map      = IDENTIFIER "=" arg ( "," IDENTIFIER "=" arg )* ;

arg           = STRING | INT | FLOAT | BOOL | NULL
              | NAMED_BINDING | POSITIONAL
              | CONTEXT_REF | COLUMN_REF | SQL_REF ;

CONTEXT_REF   = "@context" IDENTIFIER ;
COLUMN_REF    = "@column" IDENTIFIER [ "." IDENTIFIER ] ;
SQL_REF       = "@sql" ( STRING | NAMED_BINDING | POSITIONAL ) ;
```

Rule text may open with a `for <schema>` header naming the schema its conditions
and abilities belong to, which is what a [`.warrant` file](/editors/warrant-files/)
holds:

```warrant
for documents {
    if is_mine they can view
}
```

One source may hold rule sets for several schemas, each braced:

```warrant
for documents { if is_mine they can view }
for folders   { if is_mine they can view, rename }
```

A header may also stand over a single condition, `for documents is_mine or
is_public`, which scopes its names without changing the expression. Braces need a
header in front of them, and rules with no header cannot be followed by a `for`
block. A condition cannot sit beside rules in the same source.

Every form goes through the one `WarrantSyntax::parse()`, and the result says which
form it held:

| The text holds | Ask with | Read with |
|---|---|---|
| nothing, or only comments | `isEmpty()` | |
| a condition, `a or b` | `isExpression()` | `conditionExpression()` |
| one rule, `if a they can view` | `isSingleRule()` | `rule()` |
| rules, ability blocks and includes with no header | `isRuleEntries()` | `ruleEntries()`, or `scopedTo('documents')` |
| one `for documents` rule set, braced or not | `isSingleRuleSet()` | `ruleSet()` |
| several braced rule sets | `isRuleSets()` | `ruleSets()`, `forSchema('documents')`, `schemaKeys()` |
| `for documents a or b` | `isSchemaCondition()` | `conditionExpression()` |
| braced rule sets and conditions together | `isSchemaScoped()` | `scoped()` |

Asking for the wrong shape throws a `LogicException` naming what the text held,
such as `Expected a single rule, but the source holds a rule set for [documents].`

## Keywords

`if`, `they`, `can`, `cannot`, `because`, `check`, `and`, `or`, `not`, `for`,
`with`, `as`, plus the literals `true`, `false`, `null`.

None may be used as an exact condition or ability name. A name may contain or start
with one: `canonical`, `cannot_publish`, `is_and_something`.

## Operators

| | |
|---|---|
| `and` | conjunction |
| `or` | disjunction |
| `not`, `!` | negation; `not` is canonical |
| `( )` | grouping |
| `*` | every declared ability |

Precedence, tightest first: `not`, `and`, `or`.

`&&` and `||` are not accepted.

## Identifiers

`[A-Za-z_][A-Za-z0-9_-]*`. Letters, digits, underscores, and dashes, starting with
a letter or underscore. No dots.

## Literals

| Type | Written |
|---|---|
| string | `'text'` or `"text"`, escapes `\'` `\"` `\\` |
| int | `42`, `-1` |
| float | `4.5` |
| bool | `true`, `false` |
| null | `null` |

Arrays and objects have no inline form. Pass them through a binding.

## Symbolic references

| Written | Means |
|---|---|
| `@context key` | a value supplied at check time |
| `@column col` | a column of the rows this rule is about |
| `@column frame.col` | a column of a named frame |
| `@sql "..."` | a raw SQL expression, spliced verbatim |

None of the three consumes a positional `?`, and none is subject to the
every-binding-used rule. The one exception: an `@sql` body written as a binding
does consume it.

## Whitespace and comments

Whitespace is insignificant; newlines are cosmetic. An entire rule set may be one
line. `#` starts a comment to the end of the line.

```warrant
# documents a user owns
if is_mine they can view, update
```
