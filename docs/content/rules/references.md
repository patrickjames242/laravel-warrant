---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Referring to other rows
description: Column references, and the two builtins that reach another schema.
sidebar:
  order: 6
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
selector, or `its` and a row relation of the schema the rule is for.

```text
can(view for folders(@context folder_id))   # one specific folder
can(view for its folder)                    # the folder this document's relation reaches
can(access for billing)                     # the schema as a whole, no row
```

A row-bound handle compiles the other schema against that row, so its row
conditions have something to run against. It requires the target to have rows: a
rowless schema rejects a row selector.

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

## Following a relation with `its`

A schema declares how its rows reach another schema's with a
[`#[RowRelation]`](/reference/attributes/#rowrelation) method, and rule text
follows it by name:

```php
#[RowRelation(FolderSchema::class)]
public function folder(RowRelationContext $c): Builder
{
    return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
}
```

```warrant
if can(view for its folder) they can view
```

The relation's method stands where a row selector would: it says which of the
folders' rows belong to the document being checked, and the folder schema's rules
run against them. The relation is always followed from the row the rule is about,
so `its` takes no arguments.

It reads like the schema it is written on, and it reaches what a selector cannot:
a folder's documents, or a document's editors through a pivot table. Use
`schema(@column …)` when a column of this row names one row of the other schema
and no relation is declared, and `schema(@context …)` when the row is named at
check time.

```warrant
if can(view for its documents) they can view       # a folder, when they can view one of its documents
if check(is_open for its pay_period) they can submit
if can(view for folders(@context folder_id)) they can create
```

A relation may reach many rows, and then `its` asks whether **any** of them
passes. On a `check(...)` the related rows go by the relation's name, so a
predicate can write `@column folder.owner_id`, or by an alias when the reference
has one.

## How many rows: `any of` and `all of`

A handle that reaches rows may say how many of them must pass:

```warrant
if can(view for any of its documents) they can view     # one is enough
if can(edit for all of its documents) they can archive  # every one, and at least one
```

A schema handle takes one too. It matters when the target's
[`matchKey()`](/concepts/row-identity/) matches several rows, as a key by team
does:

```warrant
if check(is_staffed for all of shift_days(@column team_id)) they can publish
```

`any of` is what a handle with no quantifier already means. `all of` is true when
the handle reaches at least one row and the other schema passes every one, so a
folder with no documents is never archived by the rule above. A row on which the
other schema's rules answer null, as a comparison with a null column does, fails:
`all of` passes a row only when its rules are true.

Under `not` the whole question is negated. `not can(edit for all of its documents)`
is true when some document fails or there are none. To say none passes, write
`not can(edit for any of its documents)`.

A quantifier needs rows to count, so it goes on `its` or on a row-bound handle.
On an unbound one it is rejected:

```text
A can(...) reference to schema [documents] is quantified but selects no rows, so
there is nothing to count; add a row selector like documents(@context id), or drop
the quantifier.
```

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

An alias needs a row to name, so it is valid only on a row-bound handle or on
`its`.

## One layer

A `check(...)` predicate asks only the schema its handle names. It may hold a
`can(<ability>)`, which names one of that schema's abilities, but no `can(... for
...)` and no other `check(...)`. Inside a predicate two frames are in scope at
most: the rule's own row, and the row the handle names, under its alias when it has
one.

To ask a further schema from inside a predicate, give the target a derived
condition that asks it, and name that condition in the predicate:

```php
// On the pay periods schema
#[DerivedCondition]
public function tenantIsActive(): CrossSchemaCheckBuilder
{
    return Build::check('is_active')->for(TenantSchema::class, Ref::column('tenant_id'));
}
```

```warrant
if check(is_open and tenant_is_active for pay_periods(@column pay_period_id))
they can submit
```

"This timesheet's pay period is open, and that pay period's tenant is active." The
derived condition's text starts a scope of its own, so its `@column tenant_id`
names the pay period's row.

What a predicate may not hold is a constant. One that decides itself asks the
target nothing.
