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

:::caution[Conditions may only add `where` clauses]
A condition must compile to a boolean that Warrant can `AND`/`OR`/`NOT` together,
so it may only add `where` clauses (including `whereExists`, `whereIn`, `whereRaw`)
to `$c->query`. Calling `join()`, `groupBy()`, `having()`, an aggregate, or
`union()` throws — those change the query's row shape and can't be spliced or
negated in place. To reach another table, use a correlated
`whereExists()`/`whereNotExists()` instead of a join:

```php
return $c->query->whereExists(fn ($sub) => $sub
    ->from('team_members')
    ->whereColumn('team_members.team_id', 'documents.team_id')
    ->where('user_id', $c->user->getAuthIdentifier()));
```
:::

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

The difference from `@context`: a condition reading `$c->context` decides for
itself what an absent key means, whereas a missing _optional_ `@context` key is
passed positionally to the condition as `null` (standard SQL logic then applies —
typically `UNKNOWN`, which grants no access). See [Check-time context](/guides/context/)
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

Each condition is spliced **inline** into the compiled `WHERE` as a nested
predicate, with negation pushed onto the leaves via De Morgan (a `not` becomes
`not (…)`, or `not exists (…)` for a `whereExists`). Warrant does **not** normalize
SQL's three-valued logic: a condition that touches a `NULL` column is `UNKNOWN`, so
it contributes no access — an unknown condition never grants and never lifts a deny.
The failure direction is always safe (worst case: a legitimate user is blocked,
never unauthorized access); handle `NULL` explicitly in the condition if you want a
different outcome. See [How it compiles to SQL](/guides/how-it-compiles/).
