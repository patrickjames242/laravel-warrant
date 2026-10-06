---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Listing abilities
description: What can they do to this row, and to each row of a list.
sidebar:
  order: 5
---

A UI rarely wants one boolean. It wants to know which buttons to draw, which is a
list.

## For one row

```php
Warrant::abilities($document);                 // ['view', 'update']
DocumentSchema::guard($user)->abilities($document);
Warrant::abilities($document, ['workspace_id' => 'ws-1']);
```

The list is in [ability declaration order](/schemas/abilities/), not sorted, so
ordering the constants the way your UI reads is worth a moment.

## For every row of a list

`selectUserAbilities` attaches a computed JSON column, so a list endpoint costs one
query rather than one check per row:

```php
$rows = Document::query()->selectUserAbilities()->get();

$rows->first()->abilities;   // ['view', 'update']
```

```json
[
  { "id": "doc-1", "title": "Q3 plan",  "abilities": ["view", "update"] },
  { "id": "doc-2", "title": "Payroll",  "abilities": ["view"] }
]
```

Which is exactly the shape a frontend wants:

```jsx
{documents.map(doc => (
  <Row key={doc.id}>
    {doc.abilities.includes('update') && <EditButton/>}
    {doc.abilities.includes('delete') && <DeleteButton/>}
  </Row>
))}
```

## Narrowing it

The attached subquery grows one `UNION ALL` branch per ability, so asking for fewer
is a real saving on a wide list:

```php
Document::query()->selectUserAbilities(onlyAbilities: ['update', 'delete'])->get();
```

## Other arguments

```php
Document::query()->selectUserAbilities($user)->get();
Document::query()->selectUserAbilities(selectedAbilitiesKey: 'perms')->get();
Document::query()->selectUserAbilities(context: ['workspace_id' => 'ws-1'])->get();
```

Renaming the column matters when `abilities` collides with a real column or an
Eloquent accessor.

## On a model you already loaded

```php
$document->loadUserAbilities();              // sets $document->abilities
$document->loadUserAbilities($user, 'perms');
```

## What it compiles to

One correlated subquery per row, with a branch per ability, aggregated into JSON:

```sql
select *, (
    select coalesce(json_group_array(ability), json_array())
    from (
              select 'view'   as ability where ( /* the view predicate */ )
        union all
              select 'delete' as ability where ( /* the delete predicate */ )
    ) as available_abilities
) as abilities
from "documents"
```

Each row's column ends up holding just the abilities whose predicate held for it.

## The global scope

Warrant provides `Warrant\SelectUserAbilitiesScope`, and the trait does **not**
attach it for you. Add it yourself if you want the column on every query:

```php
protected static function booted(): void
{
    static::addGlobalScope(new \Warrant\SelectUserAbilitiesScope);
}
```

It no-ops safely when there is no authenticated user, so unauthenticated requests
simply get no column.

Think before you do this. The column costs a subquery per ability per row on every
query touching the model, including ones that will never render a button.

:::caution[Driver support]
The column uses a database-native JSON aggregate, implemented for PostgreSQL,
MySQL and MariaDB, and SQLite. Any other driver throws when the query is built:

```text
Warrant ability selection does not support the [sqlsrv] database driver.
```
:::

## Required context and enumeration

An ability declared with `#[Ability(requiredContext: [...])]` is *skipped* during
enumeration when its keys are absent, rather than throwing:

```php
Warrant::abilities($document);   // 'publish' is absent, not an error
Warrant::can('publish', $document);   // throws
```

That is on purpose. Listing what a user can do should not blow up because one
ability wanted a frame nobody supplied.
