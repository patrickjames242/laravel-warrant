---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Conditions
description: Row and global conditions, the contract they honour, and the context object.
sidebar:
  order: 4
---

A condition is a public method marked `#[RowCondition]` or `#[GlobalCondition]`.
Its job is to emit SQL. There is no in-memory evaluation path, so a condition
behaves identically whether it is filtering ten thousand rows or answering about
one. A third kind, `#[DerivedCondition]`, emits no SQL of its own: it is
[built from other conditions](/schemas/conditions-beyond-sql/).

The name a rule uses is the method name snake-cased, with nothing added or
stripped: `isMine` becomes `is_mine`, `managesTeam` becomes `manages_team`.
Override it by passing a key:

```php
#[RowCondition('is_owner')]
public function isMine(RowConditionContext $c): Builder { /* ... */ }
```

## Row conditions

A row condition narrows which rows match. Build column references with `$c->column()`
rather than writing the table yourself, because the name the rows answer to is not
always the model's table:

```php
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;

#[RowCondition]
public function isMine(RowConditionContext $c): Builder
{
    return $c->query->where($c->column('user_id'), $c->userColumn('id'));
}
```

`$c->column('user_id')` gives that column of the row, `documents.user_id`. It
always takes the column's name, the key included: `$c->column('id')`. See
[frames](/concepts/frames/) for why that matters.

To reach another table, use a correlated `whereExists` rather than a join:

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('team_members')
        ->whereColumn('team_members.team_id', $c->column('team_id'))
        ->where('user_id', $c->userColumn('id')));
}
```

`$c->column()` is an expression, so `->where('team_members.team_id', $c->column('team_id'))`
compares the two columns too. It is never bound as a value. It is also a string, already
quoted, so it can be written into raw SQL.

## The user's row

`$c->userColumn()` names a column of the user's own row, so a condition can compare
the target row with the user in SQL:

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
}
```

A column of the user's row is a subquery over the users table. It mentions nothing
from the query it lands in, so the database runs it once:

```sql
select * from "documents"
where "documents"."team_id" = (
    select "users"."team_id" from "users" where "users"."id" = 5
)
```

The user's key is already known, so naming the auth identifier's column writes the
key itself, with no subquery:

```php
return $c->query->where($c->column('owner_id'), $c->userColumn('id'));
// "documents"."owner_id" = 5
```

Like `column()`, it always takes the column's name, and it is an expression that is
also a string. Global conditions have it too:

```php
#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): Builder
{
    return $c->query->where($c->userColumn('role'), 'admin');
}
```

The users table comes from the user when it is an Eloquent model, and otherwise from
the [`user_model`](/reference/config/#user_model) config. `userColumn()` throws when
there is neither, when the user is not saved, or when the users table is on another
database connection than the query.

## Global conditions

A global condition is about the user or the world. Its context has no `column()`.
A question about the user goes through `$c->userColumn()`, so it works whether the
check is about one user or [filters users](/checking/filtering-users/):

```php
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Global\GlobalCondition;

#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): Builder
{
    return $c->query->where($c->userColumn('role'), 'admin');
}
```

A global condition may also return a `bool`. A `bool` is a constant to the
compiler, so it folds: for an admin, `if is_admin they can *` makes every predicate
true and the other conditions are never emitted at all. When the condition holds
the user, it can answer in PHP and keep the SQL for when it does not, the same way
a row condition [answers from a loaded row](#answering-in-php-when-you-hold-the-row):

```php
#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): Builder|bool
{
    // Holding the user, answer in PHP: a bool folds away.
    if ($c->user !== null) {
        return $c->user->role === 'admin';
    }

    return $c->query->where($c->userColumn('role'), 'admin');
}
```

`$c->user` is null only when users are being filtered, so the PHP branch answers
every check about one user.

A global condition may also constrain the query, like a row condition, when the
question is about the world in a way SQL has to answer.

## Why the split matters

Some checks run with no row: a [no-row check](/checking/no-row/), or a row
condition inside an unbound `check(... for <schema>)` predicate. A row condition
cannot be evaluated there, so Warrant treats it as unanswerable rather than false.
It grants nothing, and negating it grants nothing either, so it cannot lift a
denial. Global conditions still evaluate normally, which is why a schema with no
model should only declare global conditions.

## The contract

:::caution[Only where clauses, and at least one]
A condition has to compile to a boolean the compiler can `AND`, `OR`, and negate,
so it may only add `where` clauses, including `whereExists`, `whereIn`, and
`whereRaw`. A `join`, `groupBy`, `having`, aggregate, or `union` changes the
query's row shape and cannot be spliced or negated in place:

```text
Condition [manages_team] on schema [documents] may only add where clauses, but it
emitted a [join]; ...
```

Returning the query untouched throws too. It contributes no SQL and so silently
means "match every row", which is almost always a forgotten branch. To mean that on
purpose, return `true`.
:::

## Arguments

Parameters after the context object take the rule's arguments positionally, and the
details are in [passing values to conditions](/rules/values/):

```php
#[RowCondition]
public function inTeam(RowConditionContext $c, string $team): Builder
{
    return $c->query->where($c->column('team_id'), $team);
}
```

## Answering in PHP when you hold the row

A check aimed at one row often already has it loaded. In that case `$c->model` is
that model, and the condition may decide outright. Return a `bool` and Warrant folds
it, so a check whose conditions all answer this way never queries at all:

```php
#[RowCondition]
public function isPublic(RowConditionContext $c): Builder|bool
{
    if ($c->model !== null) {
        return (bool) $c->model->is_public;
    }

    return $c->query->where($c->column('is_public'), true);
}
```

`$c->model` is `null` whenever one row is not enough, such as filtering a query or
listing per-row abilities, and whenever the row is unproven, such as a bare key or
an unsaved or deleted model. Warrant only passes a model Eloquent regards as
hydrated. So the SQL branch is never optional: it is the only form that can filter.

:::caution[Both branches must agree]
They are the same rule, and Warrant does not check that they match. If they
disagree, the same user gets different answers depending on how the check was
reached. Be especially wary of reading a relation in the PHP branch: an unloaded
one lazy-loads a query you did not expect, and a stale one answers from memory
rather than from the database.
:::

## Answering unknown

A condition that cannot settle its question may return `null`:

```php
#[GlobalCondition]
public function inBillingPeriod(GlobalConditionContext $c): ?Builder
{
    $period = $c->context['period'] ?? null;

    return $period === null ? null : $c->query->where($c->userColumn('billing_period'), $period);
}
```

`null` is not `false`. It compiles to the third truth value, which negates to
itself, so it grants nothing and cannot lift a denial. Answering `false` instead
would make a missing answer capable of granting access, because `not false` is
`true`.

A condition answering `null` must leave `$c->query` untouched, and Warrant rejects
the combination rather than guessing:

```text
Condition [x] on schema [Y] returned null, answering unknown, but also added a
where clause; return the builder it constrained, or answer unknown without
constraining it.
```

Nearly always that means a missing `return`.

## The context object

The context is always the first parameter, typed to match the attribute. A missing
or wrong-typed one throws `Condition method [...] must accept a [...] as its first
parameter.`

| Property | Type | On |
| --- | --- | --- |
| `$c->user` | `?Authenticatable` | both |
| `$c->query` | `Builder` | both |
| `$c->arguments` | `array` | both |
| `$c->context` | `array` | both |
| `$c->column()` | `StringableExpression` method | row only |
| `$c->userColumn()` | `StringableExpression` method | both |
| `$c->model` | `?Model` | row only |
| `$c->table` | `string` | row only |
| `$c->keyColumns` | `array` | row only |

`$c->user` is null when users are filtered by the abilities they hold, with
`Warrant::filterUsers()`, since that answers for every user at once. Compare with
the user through `$c->userColumn()`, which works either way.

## Always bind values

:::danger[Never interpolate into SQL]
Conditions run against user-supplied and rule-supplied data. Interpolating a value
into a SQL string is an injection vector.

```php
// Good
$c->query->whereRaw("{$c->column('user_id')} = ?", [$c->user->getAuthIdentifier()]);

// Bad
$c->query->whereRaw("{$c->column('user_id')} = {$c->user->getAuthIdentifier()}");
```

Note that the column reference is interpolated and the *value* is bound. That is
the right split: `$c->column()` returns an identifier Warrant quoted itself.
:::
