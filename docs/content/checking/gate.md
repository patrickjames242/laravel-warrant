---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The Gate bridge
description: "Warrant behind $user->can(), @can, and can: routes, alongside your existing policies."
sidebar:
  order: 8
---

Warrant resolves through Laravel's Gate, so the authorization calls already in your
application keep working and start answering from your rules.

```php
$user->can('view', $document);
$user->cannot('delete', $document);
$user->canAny(['view', 'approve'], $document);
Gate::authorize('update', $document);
```

```blade
@can('update', $document)
    <a href="{{ route('documents.edit', $document) }}">Edit</a>
@endcan

@cannot('delete', $document)
    <span class="muted">Deleting is disabled for this document.</span>
@endcannot
```

```php
Route::get('/documents/{document}', ...)->middleware('can:view,document');
```

`Gate::authorize` throws Warrant's exception, so the 403 carries the responsible
rule's [message](/rules/denial-messages/).

## How it works, and why it coexists with policies

Warrant registers a `Gate::before` hook. When you call any Gate surface, the hook
runs first and checks whether the ability belongs to one of your registered Warrant
schemas. If it does, Warrant answers. If it does not, the hook returns `null` and
Laravel falls through to whatever would have handled it: your own policies, gate
closures, `can:` routes.

That fall-through is what makes incremental adoption work. Warrant and your
existing policies coexist, and you move abilities over one at a time rather than
all at once. See [running alongside
policies](/recipes/alongside-policies/).

Guests always fall through, since every Warrant check requires a user.

## Argument shapes

The Gate has no separate context argument, so context rides along as a tuple:

```php
$user->can('view', $document);                           // targeted
$user->can('approve', [$document, ['region' => 'us']]);  // targeted, with context
$user->can('create', Document::class);                   // no row
$user->can('create', [Document::class, ['region' => 'us']]); // no row, with context
```

All-of and any-of across several abilities is native Laravel:

```php
$user->can(['view', 'update'], $document);      // all
$user->canAny(['view', 'approve'], $document);  // any
```

## Turning it off

```php
// config/warrant.php
'register_gate' => false,
```

Then only the facade, the guards, the scopes, and the `warrant` middleware reach
Warrant. Worth doing if you have an ability name colliding between a Warrant schema
and an existing policy and want the policy to keep winning while you sort it out.

## When to reach past it

The Gate is the right default for a plain yes or no. Go to the facade or a guard
when you need something the Gate has no shape for:

```php
Warrant::abilities($document);                        // a list
Warrant::can('publish', $document, ['ws' => 'ws-1']); // context, named
Warrant::couldEverHave(Document::class, 'update');    // reachability
Document::query()->userHasAbility('view')->get();     // rows
```
