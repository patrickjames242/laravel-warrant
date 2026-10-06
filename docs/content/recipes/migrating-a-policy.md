---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Migrating one policy
description: A real policy class converted method by method, with the intermediate states shown.
sidebar:
  order: 12
---

Here is a policy of the kind most applications have, converted in full.

## Before

```php
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($document->user_id === $user->id) {
            return true;
        }

        return $user->teams->contains($document->team_id);
    }

    public function update(User $user, Document $document): bool
    {
        if ($document->locked && ! $user->is_admin) {
            return false;
        }

        return $document->user_id === $user->id
            || $user->teams->where('pivot.role', 'manager')->contains($document->team_id);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->is_admin || $document->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return ! $user->is_readonly;
    }
}
```

Somewhere else in the codebase, and this is the part that matters:

```php
class DocumentController
{
    public function index()
    {
        // The same logic, written again, in a query.
        return Document::query()
            ->where(fn ($q) => $q
                ->where('user_id', auth()->id())
                ->orWhereIn('team_id', auth()->user()->teams->pluck('id')))
            ->paginate();
    }
}
```

Those two are already out of sync: the policy grants admins everything, the query
does not.

## Step 1: name the vocabulary

Read the policy and pull out the nouns. Abilities are the method names. Conditions
are the questions each method asks.

Questions asked: is the user an admin, is the document theirs, is it in one of
their teams, do they manage that team, is it locked, is the user read-only.

```php
namespace App\Warrant;

class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';
    #[Ability] public const DELETE = 'delete';
    #[Ability] public const CREATE = 'create';

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }

    #[GlobalCondition]
    public function isReadonly(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_readonly;
    }

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
    }

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

    #[RowCondition]
    public function isLocked(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('locked'), true);
    }
}
```

Note what changed about `inMyTeam`. The policy used `$user->teams->contains(...)`,
which is a loaded collection. The condition is a correlated subquery, because it has
to work when there is no `$document` in hand.

## Step 2: translate each method

Method by method, with the early returns turned into clauses.

`view`: an admin, or mine, or my team's.

```warrant
if is_admin or is_mine or in_my_team they can view
```

`update`: mine or a team I manage, and never once locked, unless admin. The early
`return false` is a `cannot`, and the `&& ! $user->is_admin` is its guard:

```warrant
if is_mine or manages_my_team they can update
if is_locked and not is_admin they cannot update
    because 'This document is locked.'
if is_admin they can update
```

`delete`: admin or mine.

```warrant
if is_admin or is_mine they can delete
```

`create`: everyone except read-only users. It names no row, so the condition has to
be global, which `isReadonly` is:

```warrant
if not is_readonly they can create
```

Together, using an [ability block](/rules/ability-blocks/) for the one ability with
several rules:

```warrant
for documents {
    if is_admin they can *

    if is_admin or is_mine or in_my_team they can view

    can they update {
        if is_mine or manages_my_team they can
        if is_locked and not is_admin they cannot
            because 'This document is locked.'
    }

    if is_admin or is_mine they can delete

    if not is_readonly they can create
}
```

The `if is_admin they can *` line makes the three explicit `is_admin` mentions
redundant. Keeping them is harmless; dropping them is tidier.

## Step 3: watch for the shape that does not translate

Most policies have one method that is not a predicate over a row. Look for:

**A method that queries.** `$user->documents()->count() < 10` is about the user, so
it is a global condition and it runs one query per compile rather than per row.

**A method that reads a relation.** `$document->folder->owner_id === $user->id` is
a hop:

```warrant
if can(view for folders(@column folder_id)) they can view
```

**A method with a side effect.** Logging, or touching a timestamp. That does not
belong in authorization at all, and a condition is not the place to keep it.

## Step 4: run both, and compare

The Gate bridge falls through for abilities no registered schema declares, so you
can move one ability at a time. A stronger move is to run both and assert they
agree, in production, for a while:

```php
class DocumentPolicy
{
    public function update(User $user, Document $document): bool
    {
        $legacy  = $this->legacyUpdate($user, $document);
        $warrant = Warrant::can('update', $document, user: $user);

        if ($legacy !== $warrant) {
            Log::warning('warrant divergence', [
                'ability' => 'update',
                'document' => $document->id,
                'user' => $user->id,
                'legacy' => $legacy,
                'warrant' => $warrant,
            ]);
        }

        return $legacy;   // keep the old answer until the log is quiet
    }
}
```

Register the schema only once that log is quiet, since registering it makes the
Gate bridge take over.

## Step 5: delete the duplicated query

This is why you did it:

```php
public function index()
{
    return Document::query()->userHasAbility('view')->paginate();
}
```

And the per-row buttons come for free:

```php
return Document::query()
    ->userHasAbility('view')
    ->selectUserAbilities(onlyAbilities: ['update', 'delete'])
    ->paginate();
```

## Step 6: delete the policy

```php
// app/Policies/DocumentPolicy.php  — gone
```

Remove its registration if you registered it explicitly. If you have several
policies and are converting them one at a time, see
[running alongside policies](/recipes/alongside-policies/).

## A test worth keeping from the old code

Your existing policy tests are the specification. Point them at the new call and
they should pass unchanged:

```php
it('lets a team manager edit a team document', function () {
    expect(Warrant::can('update', $teamDoc, user: $manager))->toBeTrue();
});

it('stops anyone but an admin editing a locked document', function () {
    expect(Warrant::can('update', $locked, user: $owner))->toBeFalse();
    expect(Warrant::can('update', $locked, user: $admin))->toBeTrue();
});
```

Add one the old tests could not have:

```php
it('filters the index to exactly what view allows', function () {
    $visible = Document::query()->userHasAbility('view', $user)->pluck('id');

    $expected = Document::all()
        ->filter(fn ($d) => Warrant::can('view', $d, user: $user))
        ->pluck('id');

    expect($visible->sort()->values())->toEqual($expected->sort()->values());
});
```

That test passing is the property the old code could not offer.
