---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Filtering users
description: The hasAbilityOn scope, which finds the users who hold an ability on a row.
sidebar:
  order: 4
---

[Filtering many rows](/checking/many-rows/) asks which rows one user can act on.
This page asks the opposite: which users can act on one row.

```php
User::query()->hasAbilityOn('edit', $document)->get();
```

The scope comes from `AuthorizesWithWarrant` on the user model, the same trait
that gives `$user->warrant()`:

```php
use Warrant\AuthorizesWithWarrant;
use Warrant\HasWarrantSchema;

class User extends Authenticatable
{
    use AuthorizesWithWarrant;

    // Only if users are also things abilities apply to.
    use HasWarrantSchema;
}
```

The two scopes mirror each other. `userHasAbility` takes a user and keeps the rows
that user can act on. `hasAbilityOn` takes a row and keeps the users who can act
on it. On a user model with both traits, they answer opposite questions:

```php
// the users $bob can view
User::query()->userHasAbility('view', $bob);

// the users who can view $bob
User::query()->hasAbilityOn('view', $bob);
```

Without the trait, `Warrant::filterUsers()` does the same thing:

```php
Warrant::filterUsers(User::query(), 'edit', $document);
```

## The query it produces

The target row goes inside an `exists`, correlated with each row of the users
query, and the rules compile inside it:

```sql
select * from "users"
where exists (
    select * from "documents"
    where "documents"."id" = 100
      and ("documents"."team_id" = "users"."team_id")
)
```

Inside the `exists`, the document is a real row, so every condition works as it
does for any check: `column()`, model scopes, `whereHas`, and `can(...)` to other
schemas. A key that matches no row leaves the `exists` empty, so no user gets
through.

It composes with the rest of the query, because it is just a `where`:

```php
User::query()
    ->where('active', true)
    ->hasAbilityOn('edit', $document)
    ->orderBy('name')
    ->get();
```

## How conditions see the user

One query answers for every user at once, so no single user is being asked
about. Conditions are handed a null `$c->user`, and reach the user through
`$c->userColumn()`, which here names a column of each row of the users query:

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
}
```

For a check about one user, the same condition reads that user's row with a
subquery. For a filter over users, `userColumn('team_id')` is just
`"users"."team_id"`. The condition is written once and works both ways. See
[the user's row](/schemas/conditions/#the-users-row).

A condition that reads `$c->user` fails here with PHP's error for reading a
property of null. Write comparisons with the user in SQL, through `userColumn()`.

A condition may still answer from the loaded target alone. A `true` that depends
only on the document holds for every user:

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

## Rules picked per user

The rules are resolved once, for no particular user, and your rule provider is
handed a null `$context->user`. Rules that branch inside the text, with conditions
deciding who they match, answer this question in full:

```warrant
if in_my_team they can edit
```

A provider that picks different rules for different users in PHP still works, and
some applications need one. A users filter then sees only the rules the provider
returns for no user. See [branching in the rules or in
PHP](/supplying-rules/provider/#branching-in-the-rules-or-in-php).

## The target

The target is named the way a check names one:

```php
// a loaded model
User::query()->hasAbilityOn('edit', $document);

// a schema or model class plus a key
User::query()->hasAbilityOn('edit', [Document::class, 100]);

// a class alone, for an ability with no target
User::query()->hasAbilityOn('create', Document::class);
```

With no target there is no row, so the rules compile straight onto the users
query. A row condition answers unknown, as in any
[check without a row](/checking/no-row/), and only global conditions decide:

```php
#[GlobalCondition]
public function isAdmin(GlobalConditionContext $c): Builder
{
    return $c->query->where($c->userColumn('role'), 'admin');
}
```

```sql
select * from "users" where ("users"."role" = 'admin')
```

## Arguments

```php
use Warrant\AbilityMatchMode;

User::query()->hasAbilityOn(
    ['view', 'edit'],
    $document,
    AbilityMatchMode::ANY,
    ['workspace_id' => $workspace->id],
    rowsAs: null,
);
```

The abilities, match mode and context mean what they mean for
[filtering many rows](/checking/many-rows/#arguments). `rowsAs` names the users
query's rows when its `from` does not say, as with a `fromSub()`. An aliased
`from` is read as written:

```php
User::query()->from('users as u')->hasAbilityOn('edit', $document);
// ... "documents"."team_id" = "u"."team_id" ...
```

## Users acting on users

When the target is itself a user, the users table is on both sides. The target's
rows take another name, so the two never collide:

```sql
select * from "users"
where exists (
    select * from "users" as "users_1"
    where "users_1"."id" = 2
      and ("users_1"."id" = "users"."id")
)
```

The same happens when a rule reaches the users table through `can(...)`: that
frame is renamed, and `userColumn()` still reads the outer users query.

## Limits

The schema and the users query must be on the same database connection, since a
correlated `exists` cannot span two. Otherwise it throws:

```text
Cannot filter users by schema [documents]: it is on database connection [tenant]
but the users query runs on [mysql].
```
