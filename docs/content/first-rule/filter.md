---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 5. Filter a list
description: The same rule, used as a query scope, with no second definition.
sidebar:
  order: 5
---

This is the step that pays for the rest. The rule you wrote answers "which rows?"
as well as "can they?", and you do not write anything new to get it.

```php
Document::query()->userHasAbility('view')->paginate();
```

That returns only the documents the current user may view. The `WHERE` clause is
the same predicate the boolean check ran:

```sql
select * from "documents"
where ("documents"."user_id" = 'user-7')
```

Pass a user, ask for several abilities, or require any of them instead of all:

```php
use Warrant\AbilityMatchMode;

Document::query()->userHasAbility('update', $user)->get();

Document::query()
    ->userHasAbility(['view', 'update'], matchMode: AbilityMatchMode::ALL)
    ->get();

Document::query()
    ->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ANY)
    ->get();
```

An empty ability list leaves the query alone rather than filtering everything out,
which is what you want when the list is computed:

```php
Document::query()->userHasAbility([])->count();   // every row
```

## What can they do to each row?

A list endpoint usually wants more than a filter. `selectUserAbilities` attaches a
JSON column naming what the user may do to each row, so your UI renders buttons
without a check per row:

```php
$rows = Document::query()->selectUserAbilities()->get();

$rows->first()->abilities;   // ['view', 'update']
```

Narrow it when you only care about one verb, since the subquery grows a branch per
ability:

```php
Document::query()->selectUserAbilities(onlyAbilities: ['update'])->get();
```

## Why the two can never disagree

There is one compiler. The boolean check runs the predicate as an `EXISTS`, the
scope runs it as a `WHERE`, and the ability column runs it as a correlated
subquery per row. Change the rule and all three move together, which is the thing
a hand-written `scopeVisibleTo()` beside a policy method cannot promise.

Next: [explain a denial](/first-rule/explain/).
