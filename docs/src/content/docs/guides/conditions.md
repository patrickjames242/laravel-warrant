---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Conditions
description: Row vs. global conditions, how they emit SQL, arguments, and the context bag.
sidebar:
  order: 2
---

Conditions are the predicates a rule's `if` may test. Each is a public method on
the schema, marked `#[RowCondition]` or `#[GlobalCondition]`. A condition's
one job is to **emit SQL** — there is no in-memory evaluation path, so a
condition behaves identically when filtering a list or checking one row.

## Condition names

The name a rule uses is the method name **snake-cased**, with no prefix added or
stripped: `isSelf` → `is_self`, `managesTeam` → `manages_team`.
Override it by passing a key to the attribute:

```php
#[RowCondition('is_owner')]
public function isSelf(RowConditionContext $c): Builder { /* ... */ }
```

## Row vs. global

The distinction is: _does this predicate talk about a specific row?_

### `#[RowCondition]` — constrains which rows match

Its context is a `RowConditionContext` exposing `$c->row()` — which returns the
qualified primary-key SQL id of the row under test (`documents.id`), or the
qualified name of any column you name (`$c->row('user_id')` → `documents.user_id`).
Mutate `$c->query` to add the `WHERE` fragment and return the builder:

```php
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RowCondition;

#[RowCondition]
public function isSelf(RowConditionContext $c): Builder
{
    // $c->row() === "documents.id" (the correlated row under test)
    return $c->query->whereRaw(
        'documents.user_id = ?',
        [$c->user->getAuthIdentifier()],
    );
}
```

Your predicate may reference any column of the entity's table; it's evaluated
correlated to the row under test.

:::caution[Conditions may only add `where` clauses — and must add at least one]
A condition must compile to a boolean that Warrant can `AND`/`OR`/`NOT` together,
so it may only add `where` clauses (including `whereExists`, `whereIn`, `whereRaw`)
to `$c->query`. Calling `join()`, `groupBy()`, `having()`, an aggregate, or
`union()` throws — those change the query's row shape and can't be spliced or
negated in place.

Returning `$c->query` without adding anything throws too: it contributes no SQL
and so would silently mean "match every row", which is almost always a forgotten
branch. To mean that on purpose, return `true` instead.

To reach another table, use a correlated
`whereExists()`/`whereNotExists()` instead of a join:

```php
return $c->query->whereExists(fn ($sub) => $sub
    ->from('team_members')
    ->whereColumn('team_members.team_id', 'documents.team_id')
    ->where('user_id', $c->user->getAuthIdentifier()));
```
:::

#### Answering in PHP when you already hold the row

A check aimed at one specific row often already has that row loaded —
`$guard->can('update', $document)`. In that case `$c->model` **is** that model,
and the condition may decide outright instead of describing the row in SQL.
Return a `bool` and Warrant folds it like any other constant, so a check whose
conditions all answer this way never reaches the database:

```php
#[RowCondition]
public function isSelf(RowConditionContext $c): Builder|bool
{
    if ($c->model !== null) {
        return $c->model->user_id === $c->user->getAuthIdentifier();
    }

    return $c->query->whereRaw('documents.user_id = ?', [$c->user->getAuthIdentifier()]);
}
```

`$c->model` is `null` whenever one row is not enough — filtering a query,
listing per-row abilities — or when the row is unproven: a check given a bare
key, or an unsaved or deleted model. Warrant only passes a model Eloquent
regards as hydrated (`Model::$exists`), since anything else describes a row that
may not be there. **So the SQL branch is never optional** — it is the only form
that can filter, and it still runs for every check that names a row by key.

:::caution[Both branches must agree]
The two branches are the same rule, and Warrant does not check that they match.
If they disagree, the same user gets different answers depending on how the check
was reached — `can('view', $document)` one way, a `filterQuery` listing the other.
Keep the logic in one place if you can, and be wary of reading a relation in the
PHP branch: an unloaded one lazy-loads (a query you didn't expect), and a stale
one answers from memory rather than from the database.
:::

### `#[GlobalCondition]` — about the user or the world

Its context is a `GlobalConditionContext` (no `row()`). It may mutate
`$c->query` like a row condition, or simply **return a `bool`**:

```php
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\Conditions\GlobalConditionContext;

#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): bool
{
    return (bool) $c->user->is_admin; // true = holds for this user
}
```

### Why the split matters

Some checks run with **no row** — [no-target checks](/guides/checking-access/#no-target-checks)
and `Warrant::abilities(Document::class)` with no target. In that context a row condition
can't be evaluated, so Warrant treats it as **false** (and therefore
`not <row-condition>` as **true**). Global conditions still evaluate normally. This is
why a no-model schema should only use global conditions.

## The context object

Every condition method takes the **context object as its first parameter** and
returns `Builder` (mutated) or a `bool` that decides the outcome outright — for a
global condition always, and for a row condition when it was handed `$c->model`.
The object carries:

| Property          | Type                      | Present on    |
| ----------------- | ------------------------- | ------------- |
| `$c->user`        | `Authenticatable`         | both          |
| `$c->query`       | `Builder` (query builder) | both          |
| `$c->arguments`   | `array`                   | both          |
| `$c->context`     | `array`                   | both          |
| `$c->row()`       | `string` (method)         | row only      |
| `$c->model`       | `?Model`                  | row only      |

:::caution[The context is always the first parameter, of the matching type]
The first parameter's type must match the attribute — `RowConditionContext` for
`#[RowCondition]`, `GlobalConditionContext` for `#[GlobalCondition]`. A missing or
wrong-typed first parameter throws
`Condition method [...] must accept a [...] as its first parameter.` Any parameters
after it are the condition's [arguments](#arguments).
:::

## Arguments

A condition can take arguments from the rule (`in_team('sales')`). Declare them as
**parameters after the context object**: the context is always first, then
parameter #2 binds `argument[0]`, #3 binds `argument[1]`, and so on.

```php
#[RowCondition]
public function inTeam(RowConditionContext $c, string $team): Builder
{
    // in_team('sales')  ->  $team === 'sales'
    return $c->query->whereRaw('documents.team_id = ?', [$team]);
}
```

A **variadic** parameter collects a list argument:

```php
#[RowCondition]
public function inTeams(RowConditionContext $c, string ...$teams): Builder
{
    // in_teams('sales', 'eng')  ->  $teams === ['sales', 'eng']
    return $c->query->whereIn('documents.team_id', $teams);
}
```

A parameter with a **default value** is optional — the rule may omit that
argument. Supplying **fewer** arguments than the required parameters is rejected
during rule validation; supplying **more** is fine — the extras are ignored by the
call but stay reachable via `$c->arguments`, the full positional array. A condition
that ignores arguments simply declares no parameters beyond the context.

Arguments come from [inline literals, bindings, or `@context`](/guides/rule-language/#passing-arguments-to-conditions);
a value passed via a binding reaches you **verbatim** — any PHP type, including
arrays and objects. (Type-hint a parameter only as loosely as its values allow —
use `mixed` or a nullable type for an argument that may be null, e.g. an absent
`@context` key.)

## The ambient context bag

Every condition **also** receives the full effective context on `$c->context`,
whether or not the rule passed a value via `@context`. Reach into it directly when
a condition is inherently tied to the frame — then the rule needn't mention the
key at all:

```php
#[RowCondition]
public function inCurrentWorkspace(RowConditionContext $c): Builder
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
