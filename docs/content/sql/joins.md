---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: What joins get generated
description: How hops become subqueries, how aliases are assigned, and how to read the output.
sidebar:
  order: 2
---

Warrant emits no joins on the outer query. Everything relational is a correlated
subquery, because a join changes the row shape and a predicate has to stay a
boolean that can be negated in place.

## A row-bound hop

```warrant
if can(view for folders(@context folder_id)) they can view
```

```sql
select * from "documents" where (
    exists (
        select * from "folders"
        where "folders"."id" = 'f-1'
          and ("folders"."owner_id" = 'user-7')
    )
)
```

The subquery asks two things at once: does that folder exist, and does the folder
schema grant `view` on it. The inner predicate is the folder schema's own compiled
rules.

Under negation the same subquery is emitted as `not exists (...)`:

```warrant
if not can(view for folders(@column folder_id)) they cannot view
```

Two references on sibling branches produce two independent `EXISTS` clauses. They
do not share a subquery, and they do not need to.

## A correlated hop

The common shape, where the selector is a column of the outer row:

```warrant
if check(is_open for pay_periods(@column pay_period_id)) they can submit
```

```sql
select * from "timesheets" where (
    exists (
        select * from "pay_periods"
        where "pay_periods"."id" = "timesheets"."pay_period_id"
          and ("pay_periods"."closes_at" > '2026-09-22 10:00:00')
    )
)
```

The outer table is in scope inside the subquery, which is what makes the
correlation work.

## An unbound hop

With no row to correlate against, the target's boolean tree is spliced **inline**
rather than wrapped in a subquery:

```warrant
if can(access for billing) they can export
```

If the billing schema decides with a global condition returning a `bool`, that
folds straight into the caller's predicate instead of stopping at a literal inside
a subquery nobody needed.

## Aliases

Two frames over one table always get distinct identifiers. An unnamed frame takes
the table's own name, or the same name with a numeric suffix when that is taken.
Naming it yourself makes the output readable:

```warrant
if can(view for documents(@column parent_id) as parent) they can view
```

```sql
select * from "documents" where (
    exists (
        select * from "documents" as "parent"
        where "parent"."id" = "documents"."parent_id"
          and ("parent"."owner_id" = 'user-7')
    )
)
```

This is where [`$c->row()` and
`warrantQualifyColumn()`](/concepts/frames/) stop being stylistic. A condition on
the folder schema that wrote `folders.owner_id` produces SQL naming `folders`
inside a subquery where the rows are called `parent`.

## Nesting

A `check(...)` inside a `check(...)` nests the subqueries, and the inner handle
resolves in the outer subquery's frame:

```warrant
if check(
    is_open and check(is_active for tenants(@column tenant_id))
    for pay_periods(@column pay_period_id)
) they can submit
```

```sql
select * from "timesheets" where (
    exists (
        select * from "pay_periods"
        where "pay_periods"."id" = "timesheets"."pay_period_id"
          and (
              "pay_periods"."closes_at" > '…'
              and exists (
                  select * from "tenants"
                  where "tenants"."id" = "pay_periods"."tenant_id"
                    and "tenants"."active" = 1
              )
          )
    )
)
```

Note `pay_periods.tenant_id` in the innermost correlation. The `@column tenant_id`
was read in the pay period's frame, not the timesheet's.

## Delegation with no boundary

`can(<ability>)` with no `for` emits no subquery at all. The named ability's
predicate is compiled straight into the frame the reference sits in:

```warrant
for documents {
    if is_owner they can read
    if can(read) they can comment
    if can(comment) they can share
}
```

```sql
-- the `share` predicate
select * from "documents" where ("documents"."owner_id" = 'user-7')
```

The whole chain collapses. That is worth knowing when choosing between delegation
and a hop: same-schema delegation is free, a hop costs a subquery.

## When the subquery disappears

A row-bound hop whose selector is a **hydrated model** can settle without any
subquery. The `EXISTS` was asking whether the row exists and whether the other
schema allows it, and a hydrated model has already answered the first:

```warrant
if can(view for folders(@context folder)) they can view
```

```php
$user->warrant()->can('view', $document, ['folder' => $folder]);
```

The folder schema's row conditions receive it as `$c->model` and may return a
`bool`. If the whole of that schema folds to a constant, the reference becomes that
constant and nothing is built.

A key alone cannot do this, because whether the row exists is exactly what a key
has not established.

## A virtual table

A schema whose rows come from a query is selected from as a subquery aliased to the
schema's key:

```sql
exists (
    select * from ( … the virtualTable query … ) as "shift_days"
    where "shift_days"."team_id" = "teams"."id" and …
)
```

## Reading the output

```php
Document::query()->userHasAbility('view')->toRawSql();
Document::query()->userHasAbility('view')->dd();
```

Three habits make it readable. Name your frames with `as`. Keep conditions small,
so each one is a recognizable fragment. And remember that the nesting is real: the
compiler dropped every group that carried no meaning, so what is left is the
boolean structure of your rules.
