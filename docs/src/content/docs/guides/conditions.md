---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Conditions
description: Targeted vs. global conditions, how they emit SQL, arguments, and the context bag.
sidebar:
  order: 2
---

Conditions are the predicates a rule's `if` may test. Each is a public method on
the schema, marked `#[TargetedCondition]` or `#[GlobalCondition]`. A condition's
one job is to **emit SQL** — there is no in-memory evaluation path, so a
condition behaves identically when filtering a list or checking one row.

## Condition names

The name a rule uses is the method name **snake-cased**, with no prefix added or
stripped: `isSelf` → `is_self`, `managesTeam` → `manages_team`.
Override it by passing a key to the attribute:

```php
#[TargetedCondition('is_owner')]
public function isSelf(TargetedConditionContext $c): Builder { /* ... */ }
```

## Targeted vs. global

The distinction is: _does this predicate talk about a specific row?_

### `#[TargetedCondition]` — constrains which rows match

Its context is a `TargetedConditionContext` carrying `targetSqlId` — the
qualified primary-key SQL id of the row under test (`documents.id`). Mutate
`$c->query` to add the `WHERE` fragment and return the builder:

```php
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Conditions\TargetedConditionContext;
use Warrant\TargetedCondition;

#[TargetedCondition]
public function isSelf(TargetedConditionContext $c): Builder
{
    // $c->targetSqlId === "documents.id" (the correlated row under test)
    return $c->query->whereRaw(
        'documents.user_id = ?',
        [$c->user->getAuthIdentifier()],
    );
}
```

Your predicate may reference any column of the entity's table; it's evaluated
correlated to the row under test.

### `#[GlobalCondition]` — about the user or the world

Its context is a `GlobalConditionContext` (no `targetSqlId`). It may mutate
`$c->query` like a targeted condition, or simply **return a `bool`**:

```php
use Warrant\GlobalCondition;
use Warrant\Schema\Conditions\GlobalConditionContext;

#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): bool
{
    return (bool) $c->user->is_admin; // true = holds for this user
}
```

### Why the split matters

Some checks run with **no row** — [no-target checks](/guides/checking-access/#no-target-checks)
and `getUserAbilities()` with no target. In that context a targeted condition
can't be evaluated, so Warrant treats it as **false** (and therefore
`not <targeted>` as **true**). Global conditions still evaluate normally. This is
why a no-model schema should only use global conditions.

## The context object

Every condition method takes a **single context object** and returns `Builder`
(mutated) or, for a global condition, a `bool`. The object carries:

| Property          | Type                      | Present on    |
| ----------------- | ------------------------- | ------------- |
| `$c->user`        | `Authenticatable`         | both          |
| `$c->query`       | `Builder` (query builder) | both          |
| `$c->arguments`   | `array`                   | both          |
| `$c->context`     | `array`                   | both          |
| `$c->targetSqlId` | `string`                  | targeted only |

:::caution[Exactly one parameter, of the matching type]
A condition method must accept **exactly one** parameter, and its type must match
the attribute — `TargetedConditionContext` for `#[TargetedCondition]`,
`GlobalConditionContext` for `#[GlobalCondition]`. A wrong type or an extra
parameter throws `Condition method [...] must accept exactly one [...] parameter.`
:::

## Arguments

A condition can take arguments from the rule (`in_team('sales')`). The
resolved arguments arrive on `$c->arguments`, in order:

```php
#[TargetedCondition]
public function inTeam(TargetedConditionContext $c): Builder
{
    // in_team('sales', 'eng')  ->  $c->arguments === ['sales', 'eng']
    return $c->query->whereIn('documents.team_id', $c->arguments);
}
```

A condition that ignores arguments simply never reads `$c->arguments`. Arguments
come from [inline literals, bindings, or `@context`](/guides/rule-language/#passing-arguments-to-conditions);
a value passed via a binding reaches you **verbatim** — any PHP type, including
arrays and objects.

## The ambient context bag

Every condition **also** receives the full effective context on `$c->context`,
whether or not the rule passed a value via `@context`. Reach into it directly when
a condition is inherently tied to the frame — then the rule needn't mention the
key at all:

```php
#[TargetedCondition]
public function inCurrentWorkspace(TargetedConditionContext $c): Builder
{
    // Rule is just `if in_current_workspace they can view` — no @context needed.
    return $c->query->where('documents.workspace_id', $c->context['workspace_id']);
}
```

The difference from `@context`: a condition reading `$c->context` itself always
runs and decides for itself, whereas a missing _optional_ `@context` key
soft-falses the condition automatically. See [Check-time context](/guides/context/)
for that mechanism.

## Always bind values

:::danger[Never interpolate values into SQL]
Whatever you pass into `whereRaw`, `whereIn`, etc. must be a **bound parameter**.
Conditions run against user- and rule-supplied data; interpolating a value into
the SQL string is an injection vector.

```php
// GOOD — bound
$c->query->whereRaw('documents.user_id = ?', [$c->user->getAuthIdentifier()]);

// BAD — interpolated
$c->query->whereRaw("documents.user_id = {$c->user->getAuthIdentifier()}");
```

:::

## How conditions become SQL

Every condition leaf is wrapped as an **`EXISTS`** subquery, which makes it a
strict boolean: a condition touching a `NULL` column yields `false`, not SQL's
"unknown," and negation via `NOT EXISTS` is exact. That's why `not` / `cannot`
behave predictably. See [How it compiles to SQL](/guides/how-it-compiles/).
