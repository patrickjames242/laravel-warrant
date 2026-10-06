---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Frames
description: Which table a column reference means, and the contract a model has to honour for that to keep working.
sidebar:
  order: 6
---

A rule writes `@column pay_period_id` and names no table. A condition writes
`$c->row('user_id')` and names no table. Both are deliberate, and the reason is
that the table is not knowable when the rule is written.

A **frame** is the name the rows under discussion answer to at the point a
reference is compiled. Usually it is the schema's table. It is not always.

## Three ways the frame changes

**A caller aliased the query.**

```php
$guard->filterQuery(DB::table('timesheets as t'), 'view');
```

The rows are now called `t`. A condition that hard-coded `timesheets.user_id`
produces SQL naming a table the query never mentions.

**A hop gave the rows a name.**

```warrant
if can(view for documents(@column parent_id) as parent) they can view
```

Inside that subquery, `documents` appears twice. One of them is called `parent`.

**Two frames are in scope at once.** Inside a `check(...)` predicate, both the
schema the handle named and the schema the rule is written on are visible, which is
what lets one predicate compare the two:

```warrant
if check(owner_matches(@column timesheets.owner_id) for pay_periods(@column pay_period_id))
they can view
```

## Prefer the unqualified form

`@column pay_period_id` means "the rows this rule is already about", resolved when
the rule is compiled rather than when it is written. A reference that names nothing
cannot name the wrong thing, so reach for it first.

Write `@column <name>.<column>` only when more than one frame is in scope and you
have to say which. A name may be a schema key or an alias introduced with `as`.
Anything else is rejected at validation, and the message lists what is in scope:

```text
A @column reference names [folders], which is not in scope here; the names in
scope are [timesheets, pay_periods].
```

A qualified name has to keep being right everywhere the rule is reached from, and a
rule reached through a hop sees a different set of names than one at the top of a
query. That is the argument for the unqualified form.

## What a column reference becomes

At compile time the frame resolves to whatever identifier it carries, and the whole
thing is quoted through the connection's grammar. The condition receives an
`Illuminate\Database\Query\Expression`:

```sql
`timesheets`.`pay_period_id`     -- MySQL
"timesheets"."pay_period_id"     -- PostgreSQL and SQLite
```

Because it is an `Expression`, a condition can drop it straight into
`->where(...)` or `->whereColumn(...)` and it is emitted verbatim, never re-quoted
and never bound as a value.

## Conditions: use `$c->row()`

Inside a condition, `$c->row()` is the frame-aware way to name a column. With no
argument it gives the key column; with one it gives that column:

```php
#[RowCondition]
public function isMine(RowConditionContext $c): Builder
{
    return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
}
```

```php
// Works, until someone aliases the query or reaches this schema through a hop:
return $c->query->where('documents.user_id', $c->user->getAuthIdentifier());
```

The second form is not wrong today and is silently wrong tomorrow, which is the
worst failure mode available.

## Eloquent scopes: `warrantQualifyColumn()`

A row condition is handed an Eloquent builder over its schema's model, so it can
spend a scope the model already defines instead of restating the SQL:

```php
#[RowCondition]
public function isPublished(RowConditionContext $c): Builder
{
    return $c->query->published();
}
```

That works, and it inherits the scope's habits. A scope that hard-codes its table,
or qualifies through `qualifyColumn()`, names the table always and so cannot be
reached through an alias:

```php
// Names the table, always.
public function scopeOwnedBy($query, string $userId)
{
    return $query->where($this->qualifyColumn('owner_id'), $userId);
}
```

`warrantQualifyColumn()` is the alias-aware version. Outside Warrant it behaves
exactly like `qualifyColumn()`. Inside a Warrant row condition it returns whatever
the compiler has named the row:

```php
public function scopeOwnedBy($query, string $userId)
{
    return $query->where($this->warrantQualifyColumn('owner_id'), $userId);
}
```

It is reachable from the builder too, so a scope may use whichever it has to hand:

```php
public function scopeOwnedBy($query, string $userId)
{
    return $query->where($query->warrantQualifyColumn('owner_id'), $userId);
}
```

Omit the column for the model's key. A column that already carries a table prefix
is returned untouched.

Warrant does not override `qualifyColumn()` to do this, because a trait method
beats an inherited one and a base model's own override would be silently replaced.

:::caution[This is a real correctness bug, and it is quiet]
A reused scope that names its table produces SQL referring to a table the
surrounding query does not have in scope. Depending on the database that is either
an error or, worse, a reference to an unrelated outer table. Neither shows up until
someone reaches the schema through a cross-schema hop.
:::

## One more thing the builder does not carry

The builder a condition receives carries **no global scopes**. A condition answers
the question it was asked, not whatever the model would otherwise volunteer. If a
global scope on the model is part of your access model, express it as a condition.
