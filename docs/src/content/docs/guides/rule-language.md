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

Written directly in the rule. Supported types: `string` (single- or
double-quoted), `int`, `float`, `bool`, `null`.

```text
if in_team('sales', 'eng') they can view
if seen_recently(30, true) they can view
```

Strings may be delimited by single (`'`) or double (`"`) quotes — pick whichever
avoids escaping (e.g. `"can't touch this"`). The closing quote must match the
opener; escape a quote or backslash with `\'`, `\"`, and `\\`. Lists and other
complex values **cannot** be written inline — pass them via a binding.

### Named bindings (`:name`)

Placeholders filled from a bindings array. The *name* is what matters: a binding
may be reused any number of times, appear anywhere in the string (even across
rules), and array order is irrelevant.

```php
WarrantRuleSet::fromSyntax('
    if is_specific_user(:uid) they can view
    if delegated_to(:uid) they can approve
',
    'documents',
    ['uid' => $currentUserId],   // one value, used twice
);
```

### Positional bindings (`?`)

Filled left-to-right across the *entire* string from a flat array.

```php
WarrantRuleSet::fromSyntax(
    'if in_team(?, ?) they can view',
    'documents',
    ['sales', 'eng'],            // ? ? -> 'sales', 'eng'
);
```

### Rules for bindings — enforced at parse time

- **A binding value may be any PHP value** — string, int, array, an object,
  anything. (Only *inline* literals are restricted to scalars.) Your condition
  receives it verbatim — as the corresponding parameter (and on `$c->arguments`).
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

A `@context` key needs [no declaration](/guides/context/) to be referenced — any
key name is accepted and filled from the `context:` array (mark a key
`#[RequiredContext]` only if it must be present on every check).
Unlike `:name` / `?` bindings, a `@context` reference is **not** subject to the
parse-time "every binding used / no mixing" rules — it carries no value at parse
time, may sit alongside literals and bindings, and never consumes a positional
`?`:

```text
if scoped_to('projects', @context project_id, :region) they can view
```

Full behaviour — required vs. optional keys, and how a missing optional key fails
closed — is covered in [Check-time context](/guides/context/).

## Column references (`@column`)

Sometimes an argument needs to be a **database column**, not a value — most often
to correlate a subquery against the row being checked. Write `@column
<schema>.<column>`, using a **schema key** (not a raw table name):

```text
if pay_period_matches(@column timesheets.pay_period_id) they can view
```

At compile time the schema key is resolved to its model's real table and the
identifier is quoted through the connection's grammar, so the condition receives
an `Illuminate\Database\Query\Expression` — e.g. `` `timesheets`.`pay_period_id` ``
on MySQL, `"timesheets"."pay_period_id"` on Postgres/SQLite. Because it is an
`Expression`, a condition can drop it straight into the query builder
(`->where(...)`, `->whereColumn(...)`) and it is emitted verbatim — never
re-quoted, never bound as a value.

Like `@context`, a `@column` reference carries no value at parse time, so it is
exempt from the binding rules, may sit alongside literals and bindings, and never
consumes a positional `?`. It is most useful as a cross-schema row selector, where
it correlates the outer table into the subquery:

```text
# grants view on a timesheet when its pay period is open
if check(is_open for pay_periods(@column timesheets.pay_period_id)) they can view
```

This compiles to `... exists (select * from pay_periods where pay_periods.id =
timesheets.pay_period_id and (...))`. It works identically as a `can(...)` row
selector and as a `with` map value.

The referenced schema must be registered and model-backed; an unknown schema key
or a modelless (capability) schema is rejected at validation time. Unlike
`can(...)` / `check(...)` handles, a `@column` reference **may** name the owning
schema — pointing at your own table's column is the common case.

:::caution
A `@column` reference emits a bare qualified identifier into the SQL. It is your
responsibility that the referenced table is actually in scope in the surrounding
query — the schema's own filter, or the outer query of a `check(...)` / `can(...)`
correlated subquery. Referencing an unrelated table produces a SQL error at
execution.
:::

## Raw SQL references (`@sql`)

When a column reference is not enough — you need a scalar subquery, a function
call, or any other expression the DSL has no syntax for — write `@sql "<sql>"`.
The body is a quoted string (single or double quotes, using the usual `\'` / `\"`
/ `\\` escapes), or a `:name` / `?` binding that resolves to a string — a binding
is substituted for its value at parse time, so `@sql :q` with `q => 'select 1'` is
identical to `@sql "select 1"`. Either way the body is spliced into the query
**verbatim**:

```text
if pay_period_matches(@sql "select pay_period_id from settings limit 1") they can view
```

At compile time the body is wrapped in a single pair of parentheses and handed to
the condition as an `Illuminate\Database\Query\Expression` — exactly what
`DB::raw('(' . $sql . ')')` produces. The parentheses are always added (even if
you wrote your own), so a bare `select ...` is valid as a scalar subquery in a
comparison. Nothing else is done to the string: it is never bound as a value or
re-quoted.

Like `@context` and `@column`, the resolved `@sql` reference carries no value of
its own into the compiled tree and may sit alongside literals and bindings. Note
the one difference from those two: if you write the body as a `:name` / `?`
binding, that binding *is* consumed — it feeds the SQL string and counts toward
the "every binding used / no mixing" rules just like any other placeholder. (The
string-literal form, `@sql "..."`, consumes nothing.) It works everywhere an
argument is accepted: condition parameters, `can(...)` / `check(...)` row
selectors, and `with` map values.

:::danger
`@sql` splices its body into the query with **no escaping, quoting, or binding**.
Never build an `@sql` body from untrusted input — treat it exactly like
`DB::raw()`. It is also your responsibility that any tables it names are in scope
in the surrounding query and that the fragment is valid SQL for your connection.
:::

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
arg         = STRING | INT | FLOAT | BOOL | NULL | NAMED_BINDING | POSITIONAL | CONTEXT_REF | COLUMN_REF | SQL_REF ;
CONTEXT_REF = "@context" IDENTIFIER ;
COLUMN_REF  = "@column" IDENTIFIER "." IDENTIFIER ;
SQL_REF     = "@sql" ( STRING | NAMED_BINDING | POSITIONAL ) ;
```

## Syntax errors

Malformed syntax throws `Warrant\DSL\Parsing\WarrantSyntaxException` eagerly,
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
