---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Performance
description: What the generated queries cost, which indexes matter, and how to read a plan.
sidebar:
  order: 6
---

Warrant adds a `WHERE` clause. Everything about its cost follows from that, which
means the usual query tuning applies rather than anything special.

## Where the cost sits

**Not in resolving rules.** Your resolver is called at most once per user and
schema per request. Twenty checks in a Blade loop hit it once.

**Not in compiling.** Assembling the predicate is tree work on a handful of nodes.

**In the predicate you wrote.** Each condition's SQL becomes part of a query the
database plans. A condition with a correlated subquery over an unindexed column is
exactly as expensive as writing that subquery by hand, because that is what it is.

So the question is never "is Warrant slow", it is "is this predicate indexable".

## Index what the conditions touch

Take this condition:

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('team_members')
        ->whereColumn('team_members.team_id', $c->row('team_id'))
        ->where('team_members.user_id', $c->user->getAuthIdentifier()));
}
```

It correlates on `team_members.team_id` and filters on `team_members.user_id`, so
it wants a composite index with the filtered column first:

```php
$table->index(['user_id', 'team_id']);
```

The general rule for a correlated `whereExists`: index the constant-filtered
columns first and the correlated column last. Then check the plan, because the
planner may prefer the other order depending on cardinality.

A simple ownership condition wants the obvious one:

```php
$table->index('user_id');           // documents.user_id = ?
$table->index(['team_id', 'state']); // if rules filter on both
```

## Read the plan

```php
DB::listen(fn ($q) => logger($q->sql, $q->bindings, $q->time));

$sql = Document::query()->userHasAbility('view')->toRawSql();

DB::select("explain analyze {$sql}");   // PostgreSQL
DB::select("explain {$sql}");            // MySQL, SQLite
```

Reading a plan for a Warrant query is reading a plan for the query you would have
written by hand. What is worth looking for specifically:

**A `SubPlan` or `DEPENDENT SUBQUERY` per hop.** Expected. One per cross-schema
reference. If you see more than your rules have hops, look for a condition that
adds one internally.

**A sequential scan on the outer table** when a filter should have narrowed it.
Usually a missing index, or a condition applying a function to a column.

**A subquery re-executed per row** where a semi-join would do. PostgreSQL and
recent MySQL usually transform a correlated `EXISTS` into a semi-join; older MySQL
sometimes does not. When that bites, an `IN` list resolved in PHP may genuinely be
faster for small sets, at the cost of freshness.

## Narrow the ability column

`selectUserAbilities()` builds one `UNION ALL` branch per ability, each running that
ability's predicate as a correlated subquery, per row. A schema with twelve
abilities over a fifty-row page is six hundred predicate evaluations.

Ask for what you render:

```php
Document::query()
    ->selectUserAbilities(onlyAbilities: ['update', 'delete'])
    ->paginate();
```

That is the single highest-value tuning change available in Warrant, and it usually
takes one line.

Be careful of the global scope, which puts every ability on every query touching
the model, including ones with no UI behind them.

## Let constants do their work

Folding means an expensive condition may never be emitted. For an admin with
`if is_admin they can *`, the whole predicate is `1 = 1` and no condition SQL is
built at all.

The same applies at the other end. A user for whom nothing grants an ability gets
`1 = 0`, and the database discards the query immediately.

Boolean checks go further and skip the query entirely when the predicate folded and
the row's existence is already established:

```php
$guard->can('view', $documentFromQuery);   // no query
$guard->can('view', 42);                   // one query, for existence
```

So pass the model when you have it. It is both fewer queries and better semantics,
since a hydrated model lets row conditions
[answer in PHP](/schemas/conditions/).

## Reachability is free

`couldEverHave` and friends run no SQL. Building a nav from reachability rather
than from per-row checks removes a query per link:

```php
$nav = collect($resources)->filter(fn ($s) => Warrant::couldEverHave($s, 'view'));
```

## Cache the rule lookup, not the answer

Warrant's memo covers one request. If your resolver hits the database, cache in the
resolver:

```php
$text = Cache::remember(
    "warrant.rules.{$context->user->role_id}.{$context->schemaKey}",
    now()->addMinutes(10),
    fn () => $this->fetchRuleText($context),
);
```

Caching the *answer* to a check is usually a mistake. The predicate depends on row
state that changes, and a stale allow is a security bug where a stale rule set is
merely wrong.

## Things that make a predicate slow

- A function applied to a column, such as `lower(slug)`, which defeats an index.
- A condition that queries in PHP per compile and builds a huge `IN` list.
- A hop chain several levels deep, where each level is a nested correlated
  subquery. Flatten it with a [closure table](/recipes/hierarchies/).
- An `@sql` body doing real work per row.
- A [virtual table](/schemas/row-source/) that aggregates, since it is a subquery in
  the `FROM` and the planner often cannot push predicates into it.

## Measure before you restructure

The predicate is visible, so there is no mystery to reason around:

```php
Document::query()->userHasAbility('view')->dd();
```

Take that SQL to your usual tools. It is an ordinary query.
