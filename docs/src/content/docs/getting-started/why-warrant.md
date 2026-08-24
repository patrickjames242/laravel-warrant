---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Why Warrant?
description: The problem with the usual Laravel access-control approach, what Warrant does instead, and how it works.
sidebar:
  order: 0
---

Warrant was born out of the frustration I have had with trying to manage roles and permissions using Laravel policies and packages like [spatie/laravel-permission](https://spatie.be/docs/laravel-permission/v8/introduction) that do not genuinely solve the complexities of access control in my Laravel applications.

## The Problem

In laravel, we are frequently told that the best way to manage access control is to maintain a function that returns a boolean representing whether the user can access one instance of our data. For example:

_You can view a document if it's your own, or if you manage the team it belongs to
— but never once it's marked hidden, unless you're an admin._

```php
class DocumentPolicy
{
    public function view(User $user, Document $doc): bool
    {
        if ($user->is_admin) return true;
        if ($doc->hidden) return false;
        return $doc->user_id === $user->id
            || $user->managesTeam($doc->team_id);
    }
}
```

The problem is that you eventually need to show a list of all the documents the user can view. How do you do that with this approach? Do you pull all the documents in and filter them one by one? Nope, too inefficient. You usually just rewrite the permission logic as a separate query.

```php
Document::query()
    ->when(! $user->is_admin, fn ($q) => $q
        ->where('locked', false)
        ->where(fn ($q) => $q
            ->where('user_id', $user->id)
            ->orWhereIn('team_id', $user->managedTeamIds())))
    ->paginate();
```

And in doing so, you just violated a critical rule in programming: "Don't repeat yourself". And this is a risky instance of code duplication because permission rules drifting apart means users can't do what they need to in your app. Or worse: users can do/see what they're not supposed to.

You may suggest using the query as the source of truth and simply referencing the query in the policy function. Apart from requiring a lot of boilerplate code, this prevents you from sending specific error messages to the user at each condition branch like you could with a policy.

```php
class DocumentPolicy
{
    public function view(User $user, Document $doc)
    {
				// you can't do this 👇 if you use the query as the source of truth

        if ($doc->hidden)
					return Response::deny('The doc is hidden.');
				if (!(
						$doc->is_admin
						|| $doc->user_id === $user->id
						|| $user->managesTeam($doc->team_id)
				))
					return Response::deny('You are not authorized to view this.')
    }
}
```

Another issue is that your permissions logic is now hard coded into your app. Want to have a flexible gui where your users are able to customize what each role can do? You'd have to design your own system from scratch and refactor a whole lot of code.

Packages like [spatie/laravel-permission](https://github.com/spatie/laravel-permission) don't help much. Even [their own docs](https://github.com/spatie/laravel-permission) still recommend writing policies as seen above.

## The Solution — Warrant

Here is what Warrant proposes. Move the policy of your access control into conditional rule strings like this.

```text
if is_my_own_document or manages_team they can view, update
if is_document_locked and not is_admin they cannot view, update
if is_admin they can *
```

You can return any rule you want to for any entity in this global rule resolver. You can fetch them from the database, derive them from some ui settings, or simply hard code them in your app.

```php
class DatabaseRuleResolver implements RuleResolver
{
		public function resolve(RuleResolutionContext $context): WarrantRuleSet
		{

				$user = $context->user; // the user these rules are for

				if ($context->schemaKey === 'documents' && $user->role === 'employee'){
						return WarrantRuleSet::fromSyntax('documents', '
								if is_my_own_document or manages_team they can update
								if is_document_locked and not is_admin they cannot update
								if is_admin they can *
						');
				}
				// ...
		}
}
```

'Translate' the conditions in your ruleset via a `WarrantSchema`. Each condition in your Warrant ruleset should correspond to one camel case function in your schema.

```php

class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const UPDATE = 'update';

    #[TargetedCondition]
    public function isMyOwnDocument(TargetedConditionContext $c): Builder
    {
        return $c->query->where('documents.user_id', '=', $c->user->getKey());
    }

    /** The current user manages the team that owns the document. */
    #[TargetedCondition]
    public function managesTeam(TargetedConditionContext $c): Builder
    {
        return $c->query->whereIn('documents.team_id', function ($sub) use ($c) {
            $sub->select('team_id')
                ->from('team_managers')
                ->where('user_id', '=', $c->user->getKey());
        });
    }

    #[TargetedCondition]
    public function isDocumentLocked(TargetedConditionContext $c): Builder
    {
        return $c->query->where('documents.is_locked', '=', true);
    }

    /** Global — no target row required. */
    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return $c->user->is_admin === true;
    }
}
```

Once that's done, here's what Warrant gives you, automagically 😱😱 (once you add the [scope to your model](/getting-started/quick-start/#4-wire-it-up)).

Every check defaults to the currently authenticated user, but you can pass any user explicitly to check on their behalf.

```php

// A single boolean value representing whether or not the current user can
// view this document
$document->userHasAbility('view');
$document->userHasAbility('view', $user);

// The same check as a static call, passing the target explicitly
Document::userHasAbilities('view', $document);
Document::userHasAbilities('view', $document, $user);

// A scope that filters a list of documents by whether or not the user has the
// ability to view them
Document::query()->userHasAbility('view')->paginate();
Document::query()->userHasAbility('view', $user)->paginate();

// selectUserAbilities adds a json column to every row that looks something like
// ["view", "update"] so you know what the user can 'do' to every document
Document::query()->selectUserAbilities()->get();
Document::query()->selectUserAbilities($user)->get();

// A no-target check: can the user create documents unconditionally? (no specific row)
Document::userHasAbilities('create');
Document::userHasAbilities('create', user: $user); // named arg skips the target

// Every no-target ability the user has unconditionally, e.g. ['create']
Document::getUserAbilities();
Document::getUserAbilities(user: $user);

// middleware to guard your routes (uses the request's authenticated user)
WarrantMiddleware::guard('document', 'view', function () {
    Route::get('/documents/{document}', [DocumentController::class, 'index']);
});
```

You have just decoupled the _policy logic_ of your access control away from your code itself. 🎉

The benefit of that is that when you want to change what a user can do, you don't need to touch any policy or query code across your application. Just tweak your rule strings!

## You Keep All of Laravel's Gate

Warrant fully integrates with Laravel's Gate. Every native authorization surface
resolves Warrant abilities, so the calls you already write keep working:

```php
$user->can('view', $document);          // and $user->cannot(), canAny()
Gate::authorize('view', $document);     // throws Warrant's denial message
Route::get('/documents/{document}', ...)->middleware('can:view,document');
```

```blade
@can('view', $document)
    <a href="...">Edit</a>
@endcan
```

This works through a `Gate::before` hook. When you call any of the surfaces above,
that hook runs first, checks whether the ability belongs to one of your Warrant
schemas, and if so resolves it through Warrant. If the ability is not declared by
any registered Warrant schema, the hook returns `null` and Laravel falls through
to whatever would have handled it otherwise — your own policies, gate closures, or
`can:` routes. So Warrant and your existing policies coexist, and you can move
abilities over one at a time rather than all at once.

The hook is registered by default. Set `register_gate` to `false` in
`config/warrant.php` to skip it.

## So How Does It Work?

Your rules never run in PHP. Warrant compiles them into SQL and hands that to the
database. Because the rule ends up as a query, the same one rule set can check a
single record, filter a whole list, or report what a user can do to each row — and
none of it pulls your models into memory to decide.

So the `view` rules above turn into a `where` clause that looks roughly like this:

```sql
select * from documents
where (
    documents.user_id = 42             -- is_my_own_document
    or documents.team_id in (7, 12)    -- manages_team
    or 1 = 1                           -- is_admin (a global bool, true for an admin)
)
and not (
    documents.is_locked = 1            -- is_document_locked
    and not 1 = 1                      -- ...and not is_admin
)
```

That's the rough idea, not the exact output. The real SQL wraps each condition in
an `EXISTS` subquery so that `not` and null columns behave. For the full story, see
[How it compiles to SQL](/guides/how-it-compiles/).

:::note[Work in progress]
I'm currently working on collapsing redundant branches like the `1 = 1` above.
When a bool global condition resolves to a constant, the branch it sits in can
often be simplified away — an `... or 1 = 1` makes the whole `OR` true, and an
`and not 1 = 1` makes the whole `AND` false — so the compiled SQL will get tighter
in a future release.
:::

## But What About Those Error Messages?

Remember the complaint from earlier about exceptions? Using the query as the source
of truth meant giving up per-branch error messages — you couldn't tell one user
"the doc is hidden" and another "you're not authorized."

That's not a problem here. A rule set isn't one big query, it's a list of separate
rules, so you can put a message on whichever `cannot` rule did the blocking:

```php
WarrantRuleSet::fromRules('documents',
    WarrantRule::fromSyntax('if is_document_locked and not is_admin they cannot update')
        ->withDenialMessage('This document is locked and can no longer be edited.'),
    WarrantRule::fromSyntax('if is_my_own_document or manages_team they can update'),
);
```

Then call `authorize` instead of `userHasAbility`:

```php
Document::authorize('update', $document);
// throws a 403 with "This document is locked and can no longer be edited.",
// but only when the locked rule is the one that blocked the check.
```

Warrant figures out which `cannot` was responsible by running the same condition
SQL as the check, so it won't show a message for a rule that didn't actually fire.
If the user was blocked because nothing granted them the ability (rather than a
`cannot` forbidding it), you set that message on the schema instead, with
`ungrantedDenialMessage`.

Check [Denial messages](/guides/denial-messages/) for more info.
