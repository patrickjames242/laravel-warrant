---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: True, false, and unknown
description: The third truth value, where it comes from, and why it is behind most silent failures.
sidebar:
  order: 3
---

SQL predicates have three answers, not two. `TRUE`, `FALSE`, and `UNKNOWN`, the
last being what you get when `NULL` is involved. A `WHERE` keeps only the `TRUE`
rows, and `NOT UNKNOWN` is `UNKNOWN` again.

Warrant holds itself to the same three answers, deliberately. This page is the
mechanism behind most rules that quietly never grant.

## Where unknown comes from

Three sources, and they arrive from different directions.

**A `NULL` column.** The database produces it on its own:

```sql
-- documents.locked is NULL for this row
not ("documents"."locked" = 1)   -- UNKNOWN, so the row is dropped
```

**A question the compiler cannot settle.** A row condition asked with no row, a
`@column` naming rows that are not in scope, or a row selector that resolved to
nothing because an optional `@context` key was absent:

```php
Warrant::abilities(Document::class);   // no row at all
```

Every row condition in that check is unanswerable, so it contributes nothing in
either direction.

**A condition saying so.** A condition may return `null` when its question is
genuinely unanswerable rather than negative:

```php
#[GlobalCondition]
public function inBillingPeriod(GlobalConditionContext $c): ?bool
{
    $period = $c->context['period'] ?? null;

    return $period === null
        ? null                                    // no answer
        : $period === $c->user->billing_period;    // an answer
}
```

## Why not just say false

Because `false` is an answer, and negating an answer is legitimate. Under a
`cannot`, a `false` becomes `true`:

```warrant
if outside_workspace(@context workspace_id) they cannot view
```

If `outside_workspace` were `false` when the key is missing, the deny side would
be `AND NOT(false)`, which is `AND true`, and the rows would stay visible. A
question nobody could answer would have lifted a denial.

Unknown negates to itself, so it never does that:

```text
grant side:  UNKNOWN is not TRUE           -> no access added
deny  side:  AND NOT(UNKNOWN) = AND UNKNOWN -> the row is dropped
```

The failure direction is always the same one. An unknown can block a legitimate
user. It can never show someone a row they should not see. That is provable rather
than incidental: in three-valued logic, replacing any part of a predicate with
`UNKNOWN` can never turn a not-`TRUE` result into `TRUE`.

## A missing context key is not unknown

An absent optional key reaches its condition as `null`, and Warrant leaves it
there. What it means is up to the condition's SQL, and Laravel's query builder
reads a comparison with `null` as a test for `NULL`:

```php
$c->query->where($c->column('workspace_id'), null);         // "workspace_id" is null
$c->query->where($c->column('workspace_id'), '!=', null);   // "workspace_id" is not null
```

That is an answer, not unknown, so it does not fail safe:

```warrant
if in_workspace(@context workspace_id) they can view
```

```php
Warrant::can('view', $document);   // true if the document has no workspace, false otherwise
```

The check passed no `workspace_id`, and no `defaultContext()` supplied one, so the
rule grants exactly the rows whose `workspace_id` is `NULL`. Under a `cannot` the
same answer is negated: `if in_workspace(@context workspace_id) they cannot view`
is lifted from every row that has a workspace.

Three fixes, in order of preference:

Mark the key required, so the failure is loud instead of quiet:

```php
#[RequiredContext] public const WORKSPACE = 'workspace_id';
```

```text
Schema [documents] requires context key(s) [workspace_id]; supply them at the
check or via defaultContext().
```

A key that is present with a `null` value counts as supplied, so the requirement
does not catch a default or a `with` entry that comes out `null`.

Give it a default, so param-less paths such as route middleware and query scopes
still get a frame. Leave the key out when there is no value, so a requirement on it
still fires:

```php
public function defaultContext(): array
{
    $workspace = app('tenant')?->id;

    return $workspace === null ? [] : ['workspace_id' => $workspace];
}
```

Or answer unknown in the condition when the value is `null`, so it neither grants
nor lifts a denial:

```php
#[RowCondition]
public function inWorkspace(RowConditionContext $c, mixed $workspace): ?Builder
{
    return $workspace === null
        ? null
        : $c->query->where($c->column('workspace_id'), $workspace);
}
```

If absent genuinely means something, write that out with its own constraint, such
as an explicit `whereNull`, so the condition says it on purpose.

## The rule of thumb

Every context key a condition takes should be required, or the condition should
answer `null` when it gets `null`. Passed to the query builder, a `null` matches the
rows whose column is `NULL`, which grants those rows under a `can` and lifts a
`cannot` from every other row. When in doubt, require it.

The compiler's side of this, including what reaches the SQL as a literal `null`,
is in [where unknown goes in SQL](/sql/unknown/).
