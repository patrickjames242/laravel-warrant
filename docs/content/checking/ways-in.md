---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Ways in
description: Five entry points to the same engine, and which to reach for.
sidebar:
  order: 1
---

Every check runs on one authorization engine. What differs is how you get to it,
and the right choice is whichever reads best where you are standing.

## Laravel's Gate, for an everyday yes or no

```php
$user->can('view', $document);
$user->cannot('delete', $document);
Gate::authorize('update', $document);
```

```blade
@can('update', $document)
    <a href="{{ route('documents.edit', $document) }}">Edit</a>
@endcan
```

This is the default answer. It requires no new imports, works in Blade, and works
in code that predates Warrant. [The Gate bridge](/checking/gate/) explains how.

## The facade, when you need more than a boolean

Schema-less: the target names the schema.

```php
use Warrant\Facades\Warrant;

Warrant::can('update', $document);
Warrant::canAny(['view', 'approve'], $document);
Warrant::authorize('update', $document);
Warrant::abilities($document);
Warrant::can('update', $document, ['workspace_id' => 'ws-1']);
```

Reach for it when you need to pass context, use `canAny`, enumerate abilities, or
ask a no-row question.

## A user-bound guard, when you ask about one person repeatedly

```php
Warrant::guard($user)->can('update', $document);
$user->warrant()->can('update', $document);   // with AuthorizesWithWarrant
```

```php
use Warrant\AuthorizesWithWarrant;

class User extends Authenticatable
{
    use AuthorizesWithWarrant;
}
```

## A schema-bound guard, when you ask about one resource repeatedly

The schema is fixed, so the target is just the row, and a bare key is unambiguous:

```php
$guard = DocumentSchema::guard($user);            // or Warrant::forSchema(...)

$guard->can('update', $document);
$guard->can('update', 42);
$guard->can('create');
$guard->abilities($document);
```

This is also the only way in for a schema with no model, since there is no model to
start from:

```php
$guard = Warrant::forSchema(ShiftDaySchema::class);
$guard->filterQuery($guard->query(), 'view')->get();
```

## Query scopes, for lists

```php
Document::query()->userHasAbility('view')->paginate();
Document::query()->selectUserAbilities()->get();
$document->loadUserAbilities();
```

## Route middleware, for whole routes and groups

```php
use Warrant\Middleware\WarrantMiddleware;

Route::get('/documents/{document}', ...)
    ->middleware(WarrantMiddleware::string('document', 'view'));

WarrantMiddleware::canView('documents', function () {
    Route::get('/documents', [DocumentController::class, 'index']);
});
```

## The user argument

`$user` is always optional and defaults to `auth()->user()`. When none is
authenticated and none is passed, the failure is loud rather than a silent denial:

```text
Warrant requires an authenticated user or an explicit user instance.
```

The query scopes and `loadUserAbilities()` throw a `LogicException` in the same
situation.

## A quick map

| Question | Reach for |
|---|---|
| Can they, yes or no? | `$user->can(...)`, or `Warrant::can(...)` |
| Can they, and tell me why not? | [`Warrant::authorize(...)`](/rules/denial-messages/) |
| Which rows? | [`->userHasAbility(...)`](/checking/many-rows/) |
| What can they do to each row? | [`->selectUserAbilities()`](/checking/abilities/) |
| Can they, with no row in hand? | [no-row checks](/checking/no-row/) |
| Could they ever? | [reachability](/checking/reachability/) |
| Gate a route | [middleware](/checking/middleware/) |
| I hold a raw query builder | [low-level access](/checking/low-level/) |
