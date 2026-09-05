---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Cross-schema checks
description: Delegate a question to another schema with can(...) and check(...) — handles, row selectors, the with map, and the SQL each one compiles to.
sidebar:
  order: 3.7
---

A rule normally asks questions of its own schema: `if is_author they can update`
tests a condition the schema declares, about the row being checked. Sometimes the
answer lives somewhere else — a timesheet is editable because its **pay period**
is open, a document is visible because the user can view its **folder**.

The rule language has two builtins for that, and they are the only way a rule
reaches across a schema boundary:

| Builtin | Asks the other schema for | Consults the other schema's rules? |
| --- | --- | --- |
| `can(<ability> for <handle>)` | **permission** — does this user hold that ability there? | yes |
| `check(<predicate> for <handle>)` | **domain state** — do those conditions hold there? | no |

Both are expressions: they sit anywhere a condition may sit inside an `if`, and
combine with `and`, `or`, `not`, and parentheses like anything else.

```text
# permission delegated to the folder
if can(view for folders(@column documents.folder_id)) they can view

# domain state delegated to the pay period
if check(is_open and not is_locked for pay_periods(@column timesheets.pay_period_id))
they can submit
```

Throughout this page **A** is the schema the rule belongs to, and **B** the schema
being referenced.

## `can(...)` — delegate permission

`can(<ability> for <handle>)` is true when the **current user** holds `<ability>`
on B. B's rule set is resolved for that same user and compiled in place, so B's
whole policy — every `can`, every `cannot`, its conditions — decides the answer.

```text
if can(approve for pay_periods(@context period_id)) they can submit
```

This is the tool for *"they may do this here because they may do that there"*.
Because it re-enters another schema's rules, it is also the one with a cycle guard
(see [Cycles and depth](#cycles-and-depth)).

## `check(...)` — delegate a domain question

`check(<predicate> for <handle>)` evaluates a boolean expression whose leaves are
**B's own conditions**. It never looks at B's rules and never asks about
permission — it asks about the *state of a row*:

```text
if check(is_open for pay_periods(@context period_id)) they can submit
```

The predicate is a full boolean expression, so `and`, `or`, `not`, parentheses,
and condition arguments all work, resolved against B's vocabulary:

```text
if check((is_open or is_grace_period) and not is_locked for pay_periods(@context period_id))
they can submit
```

A predicate may only contain B's conditions. A nested `can(...)` or `check(...)`
inside one is rejected at validation:

```text
A check(...) predicate for schema [pay_periods] may only reference that schema's
conditions; it may not contain can(...) or a nested check(...).
```

:::tip[Which one do I want?]
Ask what the *other* schema is being asked. If the sentence is "…because the user
is allowed to X over there", that's `can(...)`. If it's "…because that row is
open / locked / archived", that's `check(...)` — and it's the cheaper of the two,
since it never resolves or compiles B's rules.
:::

## The handle: one row, or the schema itself

Both builtins take the same **handle** after `for` — a schema key, optionally
followed by a row selector:

```text
can(view for folders(@context folder_id))   # row-bound: one specific folder
can(access for billing)                     # unbound: the schema as a whole
```

- **Row-bound** — `schema(<row>)` names one row of B. B is compiled *against that
  row*, so B's row conditions have something to run against.
- **Unbound** — the bare `schema` form asks B a question with no row at all,
  exactly like a [no-target check](/guides/checking-access/#no-target-checks). B's
  row conditions are forced to `false` (`true` under negation), so this form is
  for [capability schemas](/guides/schemas/) and global conditions.

An unbound `check(...)` is stricter than an unbound `can(...)`: naming a row
condition in the predicate is a validation error rather than a silent `false`,
because the predicate would have nothing to decide.

```text
Condition [is_open] on schema [pay_periods] is a row condition and needs a specific
row, but the check(...) handle is unbound; add a row selector like pay_periods(@context id).
```

A row-bound handle also requires B to be **model-backed**. A capability schema has
no table and no rows, so a row selector on one is rejected.

### Row selectors

The value inside `schema(<row>)` identifies one row of B. It is bound into
`where <b_table>.<key> = ?`, so it must be something the database can compare
against a key: a scalar, B's **own model**, a `BackedEnum`, a `DateTimeInterface`,
or a `@column` / `@sql` reference (spliced as SQL rather than bound). See
[what a row selector may be](/guides/rule-language/#what-a-row-selector-may-be)
for the full table and the reasoning.

The two references worth calling out here:

```text
# correlate B against A's row — the outer table is in scope inside the subquery
if check(is_open for pay_periods(@column timesheets.pay_period_id)) they can view

# name the row at check time
if can(view for folders(@context folder_id)) they can view
```

An explicit `null` row selector — a `null` literal, or a `:name` binding that
resolved to null — is **rejected**, not treated as "no row":

```text
A can(...) reference to schema [folders] specifies a row target that is null;
supply a row id or a @context reference, or drop the row selector.
```

That is deliberate. A `$folder?->id` that came back null should fail loudly rather
than quietly widen a question about one row into a question about the schema.

## The `with` map: context across the boundary

B never inherits A's [check-time context](/guides/context/). Whatever bag the
check was made with belongs to A; B is handed a **fresh** one, built only from an
explicit `with` map:

```text
if can(view for folders(@context folder_id) with tenant_id = @context tenant_id)
they can view
```

Each key names one of B's context keys; each value is resolved in **A's** frame —
a literal, a binding, `@context`, `@column`, or `@sql` — and lands in B's bag under
that key. Duplicate keys in one map are a syntax error.

```text
# A's `region` becomes B's `scope`; B's rules read @context scope
if check(in_scope(@context scope) for folders(@context folder_id) with scope = @context region)
they can view
```

:::caution[The boundary is not a check entry point]
B's bag is exactly the `with` map — nothing more. A's ambient context does not
leak in, and B's `defaultContext()` and `#[RequiredContext]` enforcement belong to
the [check APIs](/guides/checking-access/), not to this boundary. If a rule of B's
needs a key, pass it in the map; a key you forget is simply absent, and an absent
optional key is [fail-closed](/guides/context/#missing-optional-context) — it can
remove access, never restore it.
:::

## Combining and negating

Both builtins are ordinary leaves of the `if` expression:

```text
if is_author
    and check(is_open for pay_periods(@column timesheets.pay_period_id))
    and not can(freeze for pay_periods(@context period_id))
they can submit
```

Negation follows the usual [De Morgan](/guides/how-it-compiles/) rule and lands on
the leaf, so a negated row-bound reference becomes `not exists (...)`. The same
happens under a `cannot`:

```text
if not check(is_open for pay_periods(@context period_id))
they cannot submit because 'That pay period is closed.'
```

[Reachability](/guides/reachability/) treats a rule containing either builtin like
any other conditional rule — it is structural, so a `can(...)` never turns into
`ALWAYS` or `NEVER` on its own.

## What it compiles to

A **row-bound** reference becomes an `EXISTS` over B's table, keyed to the
selector, with B's compiled predicate inside it:

```text
if can(view for folders(@context folder_id)) they can view
```

```sql
select * from "documents" where (
    exists (
        select * from "folders"
        where "folders"."id" = 'f-1'
            and (folders.owner = 'role-1')
    )
)
```

Under negation the same subquery is emitted as `not exists (...)`. Two references
to the same schema on sibling branches produce two independent `EXISTS` clauses.

An **unbound** reference has nothing to correlate, so B's boolean tree is spliced
**inline** into A's predicate rather than wrapped in a subquery — which lets a B
that decides outright (a global condition returning a `bool`) fold into A's
predicate instead of stopping at a literal.

### Handing over the row itself

When the row selector is a **hydrated model** of B rather than a key, the `EXISTS`
can disappear altogether. The subquery only ever asked two things — does that row
exist, and does B allow it — and a hydrated model has already settled the first.
B's row conditions receive it as `$c->model` and may
[answer in PHP](/guides/conditions/#answering-in-php-when-you-already-hold-the-row);
if the whole of B folds to a constant, the reference becomes that constant and no
subquery is built:

```text
if can(view for folders(@context folder)) they can view
```

```php
$user->warrant()->can('view', $document, ['folder' => $folder]);
```

A key alone cannot do this — whether the row exists is exactly what a key has not
established. See [How it compiles](/guides/how-it-compiles/#cross-schema-references-with-the-row-in-hand).

## Building them with the fluent builder

Row selectors and `with` values are usually runtime values, which is where the
[rule builder](/guides/rule-builder/#cross-schema-can-and-check) tends to read
better than a string:

```php
use Warrant\Builders\Ref;
use Warrant\Rules\WarrantRule;

WarrantRule::build()
    ->if('is_author')
    ->orIfCan('approve', PayPeriod::class, Ref::context('period_id'))
    ->andIfCheck(
        fn ($p) => $p->if('is_open')->andIfNot('is_locked'),
        'pay_periods',
        Ref::column('timesheets', 'pay_period_id'),
    )
    ->theyCan('submit')
    ->toRule();
```

The two things to remember: omitting `$row` gives the **unbound** handle (its
default is a `NoRow` sentinel, not `null`), and there are **no negated variants** —
negate with a group, `->ifNot(fn ($c) => $c->ifCan(...))`. Full signatures are in
the [rule-building API](/reference/rule-building-api/#cross-schema-methods-from-warrantconditionbuilder).

## Cycles and depth

Because `can(...)` compiles B's rules, and B's rules may reference C, a chain can
close on itself. The compiler tracks the `(schema, ability)` frames on the current
path and throws when one repeats:

```text
Cross-schema can(...) cycle detected: timesheets:create → pay_periods:approve →
timesheets:create. A can(...) reference must not, directly or transitively, depend
on the ability being compiled.
```

The guard is **path-scoped**, so two sibling references to the same schema are
fine — only re-entering a frame already on the path is a cycle. Nesting is also
capped at a depth of 32.

`check(...)` carries no cycle risk at all: it dispatches conditions and never
touches rules, which is another reason to prefer it when the question is about
domain state.

## Restrictions, in one place

Validation-time (`InvalidArgumentException`, when the rule set is validated or
compiled against its schema):

- A reference **may not target its own schema** — `can(...)` and `check(...)` are
  for *other* schemas; use a plain condition for your own.
- The target schema must be **registered**, and for `can(...)` it must **declare
  the ability**.
- A **row-bound handle needs a model-backed** target; a capability schema cannot
  be row-targeted.
- A row selector that resolves to **`null`** is rejected.
- A `check(...)` predicate may contain only the **target's conditions** — no
  `can(...)`, no nested `check(...)`, no constants — and on an unbound handle, no
  row conditions.
- A `@column` reference must name a **registered, model-backed** schema.

Compile-time (`InvalidArgumentException` / `RuntimeException`):

- A row selector that is a model of the **wrong schema**, or any other object with
  no meaning as a row key, is rejected rather than silently matching nothing —
  see [Cross-schema row selectors](/reference/errors/#cross-schema-row-selectors--invalidargumentexception).
- B must be on the **same database connection** as the query being filtered; a
  subquery cannot reach across connections.
- A cycle, or nesting deeper than 32.

## Grammar

```text
can_ref     = "can" "(" IDENTIFIER "for" handle ( "with" with_map )? ")" ;
check_ref   = "check" "(" expr "for" handle ( "with" with_map )? ")" ;
handle      = IDENTIFIER ( "(" arg ")" )? ;
with_map    = IDENTIFIER "=" arg ( "," IDENTIFIER "=" arg )* ;
```

`arg` is the same argument production the [rule
language](/guides/rule-language/#formal-grammar) uses everywhere else — literals,
`:name` / `?` bindings, `@context`, `@column`, and `@sql`.
