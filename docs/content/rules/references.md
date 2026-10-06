---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Referring to other rows
description: Column references, and the two builtins that reach another schema.
sidebar:
  order: 5
---

A rule normally asks questions of its own schema. Sometimes the answer is
elsewhere: a timesheet is editable because its pay period is open, a document is
visible because you can view its folder.

Two things make that possible. A column reference, which names a value on the row
the rule is already about, and the two cross-schema builtins.

## `@column` names a column, not a value

Most often you need it to correlate a subquery against the row being checked:

```warrant
if pay_period_matches(@column pay_period_id) they can view
```

That names no table on purpose. It means "the rows this rule is already about",
which is decided when the rule is compiled rather than when it is written. See
[frames](/concepts/frames/) for what it resolves to and when you would qualify it.

## `can(...)` asks about permission

`can(<ability> for <handle>)` is true when the current user holds that ability on
the other schema. That schema's rule set is resolved for the same user and compiled
in place, so its whole policy decides:

```warrant
if can(view for folders(@column folder_id)) they can view
```

Read it as "they may view this document because they may view its folder". This is
the tool for delegating permission.

## `check(...)` asks about state

`check(<predicate> for <handle>)` evaluates a boolean expression whose leaves are
the *other schema's conditions*. It never looks at that schema's rules and never
asks about permission:

```warrant
if check(is_open for pay_periods(@column pay_period_id)) they can submit
```

Read it as "they may submit this timesheet because its pay period is open".

The predicate is a full expression, so operators and arguments all work against
the target's vocabulary:

```warrant
if check((is_open or is_grace_period) and not is_locked for pay_periods(@column pay_period_id))
they can submit
```

:::tip[Which one do I want?]
Ask what the other schema is being asked. "Because the user is allowed to X over
there" is `can(...)`. "Because that row is open, locked, or archived" is
`check(...)`, which never resolves the other schema's rules at all unless its
predicate holds a `can(...)`.
:::

## The handle

Both take the same handle after `for`: a schema key, optionally followed by a row
selector.

```text
can(view for folders(@context folder_id))   # one specific folder
can(access for billing)                     # the schema as a whole, no row
```

A row-bound handle compiles the other schema against that row, so its row
conditions have something to run against. It requires the target to have rows: a
capability schema with no table rejects a row selector.

An unbound handle asks with no row at all, exactly like a
[no-row check](/checking/no-row/). The target's row conditions have nothing to run
against, so they are unanswerable, which neither grants nor lifts a denial. Global
conditions still answer normally:

```warrant
if check(tenant_ok or is_open for pay_periods) they can view
```

`tenant_ok` is global and decides what it can. `is_open` reads a column and has no
row, so it contributes an unknown. Nothing is rejected, and you never have to
remember which of a schema's conditions are which. That is the schema's business,
and it is free to change.

## Row selectors

The value inside `schema(<row>)` is bound into `where <table>.<key> = ?`, so it has
to be something a database can compare against a column. The accepted forms are in
[row identity](/concepts/row-identity/). The two worth calling out here:

```warrant
# correlate against the outer row
if check(is_open for pay_periods(@column pay_period_id)) they can view

# name the row at check time
if can(view for folders(@context folder_id)) they can view
```

An explicit `null` is rejected rather than treated as "no row":

```text
A can(...) reference to schema [folders] specifies a row target that is null;
supply a row id or a @context reference, or drop the row selector.
```

A `$folder?->id` that came back null should fail loudly rather than quietly widen a
question about one row into a question about the whole schema.

## Naming the rows with `as`

```warrant
if can(view for documents(@column parent_id) as parent) they can view
```

Two things follow. In the SQL that name becomes the subquery's alias, `from
"documents" as "parent"`, which is worth having when one table appears twice in a
query. You never have to supply one: two frames over one table always get distinct
identifiers, and an unnamed one takes the table's name or that name with a numeric
suffix.

On a `check(...)` it is also a name the predicate can use, which is the only way a
predicate can compare two frames of the same table:

```warrant
if check(owner_matches(@column documents.owner_id) for documents(@column parent_id) as parent)
they can view
```

An alias needs a row to name, so it is valid only on a row-bound handle.

## Nesting

A `check(...)` predicate may hold another `check(...)`, whose handle is read in the
target's frame, and may hold a `can(...)`:

```warrant
if check(
    is_open and check(is_active for tenants(@column tenant_id))
    for pay_periods(@column pay_period_id)
) they can submit
```

"This timesheet's pay period is open, and that pay period's tenant is active." The
inner `@column tenant_id` resolves in the pay period's frame, which is what makes
nesting worth having.

What a predicate may not hold is a constant. One that decides itself asks the
target nothing.
