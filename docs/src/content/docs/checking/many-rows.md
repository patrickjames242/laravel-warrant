---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Filtering many rows
description: The userHasAbility scope, match modes, and the query it produces.
sidebar:
  order: 3
---

The same rule that answers "can they?" answers "which rows?".

```php
Document::query()->userHasAbility('view')->paginate();
```

```sql
select * from "documents"
where ("documents"."user_id" = 'user-7'
       or exists (select * from "team_members"
                  where "team_members"."team_id" = "documents"."team_id"
                    and "team_members"."user_id" = 'user-7'))
limit 15 offset 0
```

It composes with everything else in the query, because it is just a `where`:

```php
Document::query()
    ->where('state', 'published')
    ->userHasAbility('view')
    ->latest()
    ->with('folder')
    ->paginate();
```

## Arguments

```php
use Warrant\AbilityMatchMode;

Document::query()->userHasAbility('update', $user)->get();

Document::query()
    ->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ALL)
    ->get();

Document::query()
    ->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ANY)
    ->get();

Document::query()
    ->userHasAbility('view', context: ['workspace_id' => 'ws-1'])
    ->get();
```

`ALL` is the default. Unlike the boolean helpers, the query layer takes the mode
explicitly, because there is no second method name to carry it.

:::tip[An empty ability list is a no-op]
`userHasAbility([])` leaves the SQL unchanged rather than filtering everything out,
which is what you want when the list is computed and might come back empty.
:::

## Folding across abilities

Constant folding crosses the ability boundary. Under `ANY`, one ability the user
holds unconditionally makes the whole gate `1 = 1` and the others are never
emitted. Under `ALL`, one ability they can never hold makes it `1 = 0`:

```sql
select * from "documents" where (1 = 0)
```

Seeing that in a log is usually a correct answer rather than a bug. It means the
rules settled the question without reference to any row.

## Counting and aggregating

Nothing special is needed. The filter is a `where`:

```php
Document::query()->userHasAbility('view')->count();
Document::query()->userHasAbility('approve')->where('state', 'pending')->exists();
```

## A raw query builder

When you do not have a model to start from, the schema-bound guard takes any
builder:

```php
$guard = Warrant::forSchema(Document::class, $user);

$guard->filterQuery(
    DB::table('documents'),
    'view',
    AbilityMatchMode::ALL,
    ['workspace_id' => 'ws-1'],
);
```

That is also how you filter a [virtual table](/schemas/row-source/), which has no
model:

```php
$guard = Warrant::forSchema(ShiftDaySchema::class);

$guard->filterQuery($guard->query(), 'view')->get();
```

## Aliased queries

Alias the query and the conditions follow, provided they build column references
through `$c->row()` or `warrantQualifyColumn()`:

```php
$guard->filterQuery(DB::table('documents as d'), 'view');
```

```sql
select * from "documents" as "d" where ("d"."user_id" = 'user-7')
```

A condition that hard-codes `documents.user_id` produces SQL naming a table the
query does not have. See [frames](/concepts/frames/).

## Relations

The scope is an ordinary scope, so it works wherever one does:

```php
$folder->documents()->userHasAbility('view')->get();

Folder::query()
    ->whereHas('documents', fn ($q) => $q->userHasAbility('view'))
    ->get();
```
