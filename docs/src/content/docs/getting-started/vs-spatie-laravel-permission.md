---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Laravel Warrant vs. spatie/laravel-permission
description: spatie/laravel-permission stores who has which flat permission — it doesn't express row-level rules. Warrant does. Here's the real difference, and why it matters.
sidebar:
  order: 4
  label: vs. Spatie Permission
---

If you've built a Laravel app that needs permissions, you've probably reached for
[`spatie/laravel-permission`](https://github.com/spatie/laravel-permission) at some
point. It's a popular, well-made package, and there's nothing wrong with it. But it
does a lot less than most people expect when they install it. The part of the job
you were actually hoping to hand off is still left for you to do yourself. This page
is about where that line falls, because once you see it, it's pretty clear what
Warrant is for.

## What people hope it solves

When you install something called a "permissions" package, the thing you're
usually picturing is the whole job. Something like: a user can edit a document if
it's their own, or if they manage the team it belongs to, but not once it's locked,
unless they're an admin.

That's what authorization actually looks like in most real apps. The answer depends
on which row you're asking about, what state that row is in, and how the user is
related to it. It's also the part that takes real work, and where most of the bugs
end up.

## What Spatie actually does

Here's what Spatie actually gives you. It stores flat permission strings and lets
you hand them out to users and roles. You get a few tables and a clean API for
saying "this user has the `edit documents` permission" and checking whether they
have it:

```php
$user->givePermissionTo('edit documents');   // insert a pivot row
$user->assignRole('manager');                 // insert a pivot row
$user->can('edit documents');                 // boolean lookup
```

That's useful, and I don't want to undersell it. But it's worth being honest about
how small it is. Under the hood you're inserting and deleting rows in a couple of
pivot tables and then doing a boolean lookup. A permission here is just a string the
user either has or doesn't. It doesn't know which document you mean, whether that
document is locked, who owns it, or which team it belongs to. Every question that's
actually hard is outside what it can see.

Roles don't change that. A role is just a named bundle of the same flat strings — a
convenient way to give out a group of permissions at once. It's still the same kind
of thing: a label the user carries or doesn't. Nothing in Spatie can look at a
specific row and decide anything about it.

## The part it leaves you to build

So the moment a permission has any real logic behind it, that logic comes right back
to you. Spatie doesn't hide this, to its credit. Its own best-practices guide sends
you straight to Laravel's Model Policies and calls them
["the best way to incorporate access control"](https://spatie.be/docs/laravel-permission/v8/best-practices/using-policies).
In other words, the package's own advice for anything beyond a flat yes/no is to go
write it yourself.

So for any permission that has actual rules behind it, you end up writing a policy,
and the Spatie permission becomes a single line at the top of it — the easy check —
wrapped in all the logic that actually decides the outcome:

```php
// app/Policies/DocumentPolicy.php
class DocumentPolicy
{
    public function update(User $user, Document $doc): bool
    {
        if (! $user->can('edit documents')) return false;   // ← the Spatie part

        // ...everything that actually decides the outcome is hand-written:
        if ($user->hasRole('admin')) return true;
        if ($doc->locked) return false;

        return $doc->user_id === $user->id
            || $user->managesTeam($doc->team_id);
    }
}
```

Look at how little of that method is actually Spatie. One line. Everything under it —
the admin bypass, the locked check, the ownership and team logic — is hand-written,
and it's the part that was hard in the first place. That's the pattern with Spatie:
it takes care of the one line that was never really the problem, and leaves you the
rest.

And even after all that, the policy only answers one shape of the question: can they
edit *this* one document? It can't tell you which documents they can edit, because a
policy works on a single instance and a list page doesn't have one. So you write the
same rules a second time, by hand, as a query scope:

```php
// Documents this user may edit — the policy's rules, rewritten for a query.
Document::query()
    ->when(! $user->hasRole('admin'), fn ($q) => $q
        ->where('locked', false)
        ->where(fn ($q) => $q
            ->where('user_id', $user->id)
            ->orWhereIn('team_id', $user->managedTeamIds())))
    ->get();
```

Now the same rule lives in two separate places that don't share a line of code, and
over time they drift apart. Six months from now someone loosens the locked check in
the policy, doesn't realize the scope exists, and the edit button starts saying yes
while the list quietly leaves the row out — or the reverse, which is worse, because
now people see things they shouldn't. This is one of the most common authorization
bugs in Laravel apps, and it isn't really a mistake. It's just what happens when the
same logic has to be kept in sync across two copies you maintain by hand.

And it's usually not even two copies. There's often a third, for the "what can this
user do to each of the fifty rows on this page" question you need in order to render
the right buttons. Then multiply all of it by every resource in your app.

Through all of this, Spatie is sitting underneath as a store of permission strings.
It never touches the hard part. The complexity that sent you looking for a
permissions package in the first place is still entirely yours to deal with.

## What Warrant actually solves

Warrant is built for that hard part. You write the rule **once**, as data — the
whole policy in three lines:

```text
if is_self or manages_team they can update
if is_locked and not is_admin they cannot update
if is_admin they can *
```

You hand those rules to Warrant in a **resolver** — one class, called per request,
that returns the rule set for the current user and resource. Because the rules are
just strings, they can come from anywhere: a database, derived from UI settings, or
hardcoded. They're inline here just for the example:

```php
class DocumentRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        // $context->user and $context->schemaKey tell you who's asking, and about what
        return WarrantRuleSet::fromSyntax('
            if is_self or manages_team they can update
            if is_locked and not is_admin they cannot update
            if is_admin they can *
        ', $context->schemaKey);
    }
}
```

Each condition name (`is_self`, `manages_team`, `is_locked`, `is_admin`) is defined
once in a schema, which teaches Warrant how it becomes SQL:

```php
// app/Warrant/DocumentSchema.php
class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';

    #[RowCondition] // is_self
    public function isSelf(RowConditionContext $c): Builder
    {
        return $c->query->whereRaw('documents.user_id = ?', [$c->user->getAuthIdentifier()]);
    }

    #[RowCondition] // manages_team — the current user manages the document's team
    public function managesTeam(RowConditionContext $c): Builder
    {
        return $c->query->whereIn('documents.team_id', $c->user->managedTeamIds());
    }

    #[RowCondition] // is_locked
    public function isLocked(RowConditionContext $c): Builder
    {
        return $c->query->whereRaw('documents.locked = ?', [true]);
    }

    #[GlobalCondition] // is_admin
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->hasRole('admin');
    }
}
```

And every question traces back to that one rule — no second copy to keep in sync:

```php
// A single boolean value representing whether or not the current user can
// view this document
Warrant::can('view', $document);

// The throwing sibling — aborts with a 403 (and the rule's denial message)
// if the user can't view it
Warrant::authorize('view', $document);

// A scope that filters a list of documents by whether or not the user has the
// ability to view them
Document::query()
    ->userHasAbility('view')
    ->paginate();

// selectUserAbilities adds a json column to every row that looks something like
// ["view", "update"] so you know what the user can 'do' to every document
Document::query()
    ->selectUserAbilities()
    ->get();

// middleware to guard your routes
WarrantMiddleware::guard('document', 'view', function () {
    Route::get('/documents/{document}', [DocumentController::class, 'index']);
});

// or use Laravel's Gate — Warrant resolves these too
$user->can('view', $document);
```

"Which rows?" becomes a `WHERE` clause the database answers, not a collection loaded
into memory and filtered in PHP — and the check, the filter, and the per-row column
**cannot** drift, because they compile from that single rule. See
[How it compiles to SQL](/guides/how-it-compiles/).

## Side by side

| | spatie/laravel-permission | Laravel Warrant |
|---|---|---|
| What it is | a **store** for flat permission / role assignments | the **authorization logic itself**, compiled to SQL |
| Row-level conditions (own it, same team, locked, unless admin) | not expressible — you write a Policy by hand | first-class [conditions](/guides/conditions/) in the [rule language](/guides/rule-language/) |
| "Which rows can they act on?" | not addressed — you write a query scope by hand | `Model::query()->userHasAbility('update')` → a `WHERE` clause |
| Per-row abilities for a list | a check per row × ability | [`->selectUserAbilities()`](/guides/checking-access/#per-row-abilities) → one query, a JSON column |
| Keeping the check and the filter in sync | your problem (two hand-written copies) | one source of truth; they compile together |
| Storage | ships migrations + permission / role tables | owns **no** tables; rules come from a [resolver](/guides/resolvers/) |
| Best at | assigning and looking up flat permissions | expressing and enforcing row-dependent rules |

## Can you use them together?

Yes — they operate at different layers, so Spatie (or any role system) can stay as
your **source of roles**, while Warrant does the actual authorization. Your
[resolver](/guides/resolvers/) translates the current user's roles into the rules
for a resource:

```php
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\WarrantRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        if ($context->user->hasRole('admin')) {          // Spatie answers "what role?"
            return WarrantRuleSet::fromSyntax('they can *', $context->schemaKey);
        }

        return WarrantRuleSet::fromSyntax(
            'if is_self they can view, update',           // Warrant answers "on which rows?"
            $context->schemaKey,
        );
    }
}
```

Spatie tells you *"what role is this user?"* Warrant answers the question that
actually took all the work: *"so what can they do to these rows?"*

## Next steps

- [Quick start](/getting-started/quick-start/) — a schema, a rule, a resolver, and
  the checks that use them, end to end.
- [Core concepts](/getting-started/core-concepts/) — how schemas, rules, and the
  resolver divide the work.
- [How it compiles to SQL](/guides/how-it-compiles/) — why the single-record check,
  the list filter, and the per-row abilities can never disagree.
