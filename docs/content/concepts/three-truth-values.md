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

Four sources, and they arrive from different directions.

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

**A missing optional context key.** It reaches the condition as `null`, and a
comparison against `null` is unknown.

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

## What it looks like when it bites

The symptom is a rule that grants nothing and produces no error.

```warrant
if in_workspace(@context workspace_id) they can view
```

```php
Warrant::can('view', $document);   // false, always, and silently
```

The check passed no `workspace_id`, and no `defaultContext()` supplied one, so the
condition compared a column against `null`. Every row is unknown. Nothing grants.

Three fixes, in order of preference:

Mark the key required, so the failure is loud instead of quiet:

```php
#[RequiredContext] public const WORKSPACE = 'workspace_id';
```

```text
Schema [documents] requires context key(s) [workspace_id]; supply them at the
check or via defaultContext().
```

Give it a default, so param-less paths such as route middleware and query scopes
still get a frame:

```php
protected function defaultContext(): array
{
    return ['workspace_id' => app('tenant')->id];
}
```

Or handle the null deliberately in the condition, when absent genuinely means
something:

```php
#[RowCondition]
public function inWorkspace(RowConditionContext $c, mixed $workspace): Builder
{
    return $workspace === null
        ? $c->query->whereNull($c->row('workspace_id'))
        : $c->query->where($c->row('workspace_id'), $workspace);
}
```

## The rule of thumb

Any context key that gates a `cannot` should be required. A missing key there
blocks rows rather than exposing them, so nothing unsafe happens, but a whole
class of rows going missing with no error is hard to trace. When in doubt, require
it.

The compiler's side of this, including what reaches the SQL as a literal `null`,
is in [where unknown goes in SQL](/sql/unknown/).
