---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: What a check costs
description: The query count and shape per operation, and where the cost actually sits.
sidebar:
  order: 1
---

Counting queries, for one request.

## Resolving rules

Once per user and schema, whatever your resolver does. A page checking twenty
documents and filtering two lists calls it once for `documents`.

Whether that is one query, none, or a cache hit is entirely your resolver's
business. Rules in a file or in code cost nothing.

## A boolean check

| Situation | Queries |
|---|---|
| hydrated model, predicate folded to a constant | 0 |
| hydrated model, predicate needs SQL | 1 |
| bare key, or unsaved or deleted model | 1 |
| predicate folded to `false` | 0 |

The `EXISTS` loads no records. A folded `false` short-circuits because no row could
pass. A folded `true` still costs one query when the row's existence is unproven,
because the `EXISTS` was confirming that too.

```php
$guard->can('view', $documentFromQuery);   // 0 or 1
$guard->can('view', 42);                   // 1
```

So pass the model when you have it.

## Filtering a list

Zero extra queries. `userHasAbility` adds a `WHERE` to a query you were running
anyway.

What it can add inside that clause is one correlated subquery per condition that
reaches another table, and one per cross-schema hop.

## The per-row ability column

One subquery attached to the list query, containing one `UNION ALL` branch per
requested ability, each evaluating that ability's predicate per row.

Still one round trip. The work is inside the database, and it scales with rows
times abilities:

```php
Document::query()->selectUserAbilities()->paginate();                       // 12 abilities
Document::query()->selectUserAbilities(onlyAbilities: ['update'])->paginate(); // 1
```

Narrowing is the highest-value change available here.

## Reachability

Zero queries. It reads the resolved rule set's shape, evaluates no conditions, and
runs no SQL. Building a nav from it costs nothing beyond resolving the rules once.

```php
Warrant::couldEverHave(Document::class, 'view');    // no SQL
Warrant::possibleAbilities(Document::class);        // no SQL
```

## Diagnosing a denial

`authorize()` costs the check, plus diagnosis when it fails. Diagnosis walks the
rules and runs the same condition SQL as the check to find which `cannot`
actually matched, so a denial is more expensive than an allow.

That is the right trade: denials are rare and the message is worth it.

## A realistic page

An index endpoint with fifty rows, a nav, and per-row buttons:

```php
$nav = collect($resources)->filter(fn ($s) => Warrant::couldEverHave($s, 'view'));  // 0

$documents = Document::query()
    ->userHasAbility('view')                                    // 0 extra
    ->selectUserAbilities(onlyAbilities: ['update', 'delete'])  // 0 extra
    ->paginate();                                               // the query you had
```

One query, plus whatever your resolver spent. The naive version of the same page,
with a policy call per row per button, is a hundred and one.

## Where it actually gets slow

Not in Warrant. In the predicate you wrote:

- an unindexed column in a condition;
- a correlated subquery over a large table with no supporting index;
- a hop chain nesting several subqueries;
- a virtual table that aggregates, which the planner often cannot push predicates
  into;
- an ability column over many abilities on a long page.

All of it is ordinary query tuning, because the output is an ordinary query. See
[performance](/sql/performance/).
