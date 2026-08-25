---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The rule language
description: The full Warrant rule DSL — clauses, boolean logic, precedence, wildcards, arguments, and errors.
sidebar:
  order: 3
---

Rules are the *policy itself*, written as a plain string. You'll typically store
these strings (per role, per user, per tenant) and load them in your
[resolver](/guides/resolvers/).

Throughout, **"they" is the current user** — the one your resolver was asked
about. A rule set describes what *this* user can do with the resource it's scoped
to, not what everyone can do.

## Anatomy of a rule

A rule is an optional `if <expression>` followed by one or more `they can` /
`they cannot` clauses:

```text
if is_self
they can view, update
they cannot delete
```

- **`if <expression>`** — optional. When present, the clauses apply only where the
  expression holds. When omitted, the rule is **unconditional** (always applies).
- **`they can <abilities>`** — grants the listed abilities.
- **`they cannot <abilities>`** — denies the listed abilities.

Abilities are comma-separated. A rule may freely mix `can` and `cannot` clauses.

A `cannot` clause may also carry a **denial message** with `because '<message>'`,
surfaced when that rule is the cause of a denial — see
[Denial messages](/guides/denial-messages/#in-the-string-dsl):

```text
if is_locked
they cannot update because 'This document is locked.'
```

## `can`, `cannot`, and how they combine

Warrant combines grants and denials with one rule: **a `cannot` always beats a
`can`**. For a given ability the compiled predicate is:

```text
( any `can` rule for it matches )  AND  ( no `cannot` rule for it matches )
```

- **A `cannot` is an absolute veto.** An unconditional `they cannot delete` means
  this user can *never* delete any row — no `can` rule can bring it back.
- **An ability with no `can` rule is denied.** Silence is not permission.
- **Rule order does not matter** — the combination is commutative.

This user can view every row, but never update or delete a locked one — even if
another rule grants `update`:

```text
they can view
if is_locked
they cannot update, delete
```

See [Grants and denials](/guides/grants-and-denials/) for the full semantics.

## Boolean logic

The `if` expression is a boolean combination of conditions:

```text
if is_self or is_manager
if is_self and not is_locked
if is_manager and (in_team('sales') or in_team('eng'))
```

- **`and`**, **`or`** — binary operators.
- **`not`** — negation. `!` is an accepted synonym (`!is_locked` ≡ `not is_locked`);
  `not` is the canonical spelling.
- **Parentheses** group sub-expressions.

Each bare name (`is_self`, `is_manager`) is a condition declared on the
[schema](/guides/schemas/).

:::note
`&&` and `||` are **not** supported — use `and` / `or`.
:::

## Operator precedence

From tightest to loosest binding: **`not` / `!`  >  `and`  >  `or`**. Parentheses
override. So:

```text
if is_self or not is_manager and is_owner
```

parses as `is_self OR ((NOT is_manager) AND is_owner)`. When in doubt,
parenthesize.

## Wildcards

`*` stands for **every ability the schema declares**, on both sides — an admin gets
every ability; a suspended user loses every one (a lockout that wins):

```text
if is_admin
they can *

if is_suspended
they cannot *
```

`they cannot *` is the idiomatic **kill switch** — it vetoes every ability at once.

## Passing arguments to conditions

A condition can take arguments in three ways resolved *before* compilation —
inline literals, named bindings, and positional bindings. A fourth source,
[check-time context](/guides/context/), is resolved later, when the check runs.

### Inline literals

Written directly in the rule. Supported types: `string` (single-quoted), `int`,
`float`, `bool`, `null`.

```text
if in_team('sales', 'eng') they can view
if seen_recently(30, true) they can view
```

Strings use single quotes; escape a quote or backslash with `\'` and `\\`. Lists
and other complex values **cannot** be written inline — pass them via a binding.

### Named bindings (`:name`)

Placeholders filled from a bindings array. The *name* is what matters: a binding
may be reused any number of times, appear anywhere in the string (even across
rules), and array order is irrelevant.

```php
WarrantRuleSet::fromSyntax('documents', '
    if is_specific_user(:uid) they can view
    if delegated_to(:uid) they can approve
',
    ['uid' => $currentUserId],   // one value, used twice
);
```

### Positional bindings (`?`)

Filled left-to-right across the *entire* string from a flat array.

```php
WarrantRuleSet::fromSyntax('documents',
    'if in_team(?, ?) they can view',
    ['sales', 'eng'],            // ? ? -> 'sales', 'eng'
);
```

### Rules for bindings — enforced at parse time

- **A binding value may be any PHP value** — string, int, array, an object,
  anything. (Only *inline* literals are restricted to scalars.) Your condition
  receives it verbatim on `$c->arguments`.
- **You may not mix** named and positional bindings in one parse.
- **Every placeholder must have a value, and every provided value must be used.**
  A missing binding, an unused binding, or a positional count mismatch is an error.

## Check-time context (`@context`)

Some values are known only **when the check runs** — the current tenant, an
academic year, an as-of date. Reach these with `@context <key>`, which stays
symbolic in the rule and is filled from a `context:` array at check time:

```text
if in_workspace(@context workspace_id) they can view, edit
```

The key must be [declared on the schema](/guides/context/) with `#[ContextKey]`.
Unlike `:name` / `?` bindings, a `@context` reference is **not** subject to the
parse-time "every binding used / no mixing" rules — it carries no value at parse
time, may sit alongside literals and bindings, and never consumes a positional
`?`:

```text
if scoped_to('projects', @context project_id, :region) they can view
```

Full behaviour — required vs. optional keys, and how a missing optional key fails
closed — is covered in [Check-time context](/guides/context/).

## Whitespace, multiple rules, reserved words

- **Whitespace is insignificant.** Newlines are cosmetic; an entire rule set can
  be one line. These are identical:

  ```text
  if is_self they can view if is_manager they can approve
  ```
  ```text
  if is_self
  they can view

  if is_manager
  they can approve
  ```

- **`if` starts a new rule.** Every `if` begins a new rule; `they can/cannot`
  clauses attach to the most recent `if` above them. Clauses before any `if` form
  a single leading unconditional rule.

- **Reserved words** — `if`, `they`, `can`, `cannot`, `because`, `and`, `or`,
  `not` — cannot be used as an *exact* condition or ability name. A name may
  *contain* or *start with* one, though: `canonical`, `cannot_publish`,
  `is_and_something` are all fine.

- **Identifiers** (condition, ability, and binding names) match
  `[A-Za-z_][A-Za-z0-9_-]*` — start with a letter or underscore; may contain
  letters, digits, underscores, and dashes. No dots.

## Formal grammar

```text
ruleset     = clause* ( "if" expr clause+ )* ;
clause      = "they" ( "can" ability ( "," ability )*
                     | "cannot" ability ( "," ability )* ( "because" message )? ) ;
ability     = IDENTIFIER | "*" ;
message     = STRING | NAMED_BINDING | POSITIONAL ;
expr        = or ;
or          = and ( "or" and )* ;
and         = not ( "and" not )* ;
not         = ( "not" | "!" ) not | primary ;
primary     = "(" expr ")" | condition ;
condition   = IDENTIFIER ( "(" ( arg ( "," arg )* )? ")" )? ;
arg         = STRING | INT | FLOAT | BOOL | NULL | NAMED_BINDING | POSITIONAL | CONTEXT_REF ;
CONTEXT_REF = "@context" IDENTIFIER ;
```

## Syntax errors

Malformed syntax throws `Warrant\RuleSyntaxTree\WarrantSyntaxException` eagerly,
with the line, column, and a caret pointing at the offending token — debuggable
even when the whole rule set is one line:

```text
Reserved word 'can' cannot be used as a name; expected an ability name. (line 1, column 21)

    if is_self they can can
                        ^
```

Name validation (does this ability/condition actually exist on the schema?)
happens later, at **compile time**, when a rule set is compiled against a schema —
also a hard error. See [Errors & exceptions](/reference/errors/) for the catalogue.
