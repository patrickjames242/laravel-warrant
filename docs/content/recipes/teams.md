---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Teams and membership
description: Access that follows membership, with the join written as a correlated subquery.
sidebar:
  order: 2
---

The most common relational permission: you may act on a row because you belong to
the group that owns it.

## The tables

```php
Schema::create('teams', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});

Schema::create('team_members', function (Blueprint $table) {
    $table->foreignId('team_id')->constrained();
    $table->foreignId('user_id')->constrained();
    $table->string('role')->default('member');   // member | manager
    $table->primary(['team_id', 'user_id']);
});

Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained();
    $table->foreignId('user_id')->constrained();
    $table->boolean('locked')->default(false);
});
```

Index `team_members` on `(user_id, team_id)` as well. The generated subquery
correlates on `team_id` and filters on `user_id`, so it wants both orders
available.

## The conditions

Two of them, as a correlated `whereExists` rather than a join, since a condition
may not change the query's row shape:

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('team_members')
        ->whereColumn('team_members.team_id', $c->row('team_id'))
        ->where('team_members.user_id', $c->user->getAuthIdentifier()));
}

#[RowCondition]
public function managesMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('team_members')
        ->whereColumn('team_members.team_id', $c->row('team_id'))
        ->where('team_members.user_id', $c->user->getAuthIdentifier())
        ->where('team_members.role', 'manager'));
}
```

## The rules

```warrant
for documents {
    if in_my_team they can view

    can they update {
        if is_mine or manages_my_team they can
        if is_locked and not manages_my_team they cannot
            because 'This document is locked. Ask a team manager.'
    }

    if manages_my_team they can delete
}
```

## What it produces

```php
Document::query()->userHasAbility('view')->get();
```

```sql
select * from "documents"
where (exists (
    select * from "team_members"
    where "team_members"."team_id" = "documents"."team_id"
      and "team_members"."user_id" = 7
))
```

One correlated subquery, no `IN` list to build in PHP, and it stays correct as
memberships change between requests.

## Passing team ids instead

Sometimes you already have the ids and would rather not re-query:

```php
#[RowCondition]
public function inMyTeam(RowConditionContext $c): Builder
{
    return $c->query->whereIn($c->row('team_id'), $c->user->teamIds());
}
```

```sql
where ("documents"."team_id" in (3, 9, 14))
```

Simpler, and cheaper when the count is small. The trade is that the list is
resolved in PHP, so it is only as fresh as whatever loaded it, and it degrades
badly for a user in hundreds of teams. Prefer the subquery; reach for the `IN` when
you have measured.

## Teams that own other things

The same two conditions belong on every team-owned schema, which is a good use for
a base class:

```php
abstract class TeamOwnedSchema extends WarrantSchema
{
    #[RowCondition]
    public function inMyTeam(RowConditionContext $c): Builder
    {
        return $c->query->whereExists(fn ($sub) => $sub
            ->from('team_members')
            ->whereColumn('team_members.team_id', $c->row('team_id'))
            ->where('team_members.user_id', $c->user->getAuthIdentifier()));
    }
}

class DocumentSchema extends TeamOwnedSchema { public const model = Document::class; /* ... */ }
class ProjectSchema  extends TeamOwnedSchema { public const model = Project::class;  /* ... */ }
```

Each concrete schema still needs its own model.

## When the row does not carry a team

A comment belongs to a document, which belongs to a team. Rather than denormalize,
delegate:

```warrant
for comments {
    if can(view for documents(@column document_id)) they can view
    if is_author they can update, delete
}
```

Now the team rules live in one place and comments follow.

## Testing

```php
it('scopes documents to the teams a user belongs to', function () {
    $user = User::factory()->create();
    $mine = Team::factory()->hasAttached($user)->create();
    $other = Team::factory()->create();

    $visible = Document::factory()->create(['team_id' => $mine->id]);
    $hidden  = Document::factory()->create(['team_id' => $other->id]);

    $ids = Document::query()->userHasAbility('view', $user)->pluck('id');

    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});
```

## Related

[Multi-tenancy](/recipes/multi-tenancy/) when the grouping is the whole account
rather than a team inside it. [Hierarchies](/recipes/hierarchies/) when teams nest.
