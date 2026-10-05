---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Route middleware
description: Gate routes with the warrant middleware — no-target and targeted checks, helpers, and groups.
sidebar:
  order: 10
---

Warrant registers a `warrant` route middleware. You rarely write the raw string
yourself — build it with `Warrant\Middleware\WarrantMiddleware`.

:::note[Laravel's `can:` middleware works too]
Because Warrant [integrates with the Gate](/guides/checking-access/#laravels-gate),
Laravel's built-in `can:` route middleware resolves Warrant abilities as well —
`->middleware('can:view,document')`. The `warrant` middleware below adds no-target
checks by schema key, standard-ability helpers, route-group guards, and
reachability guards on top of that.
:::

## No-target checks

Gate a route by schema key — no model involved:

```php
use Warrant\Middleware\WarrantMiddleware;

Route::post('/documents', [DocumentController::class, 'store'])
    ->middleware(WarrantMiddleware::canCreate('documents'));
```

Under the hood `canCreate('documents')` produces the middleware string
`warrant:documents,create`.

## Targeted checks

Gate by a route-model-bound parameter. The parameter must resolve to a **model
instance**; Warrant finds its schema from the model's class:

```php
Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->middleware(WarrantMiddleware::string('document', 'view'));
```

:::caution
If the route parameter doesn't resolve to a model instance (e.g. it's a raw id
with no route-model binding), the middleware throws
_"route parameter [...] must resolve to a model instance."_ Make sure the
parameter is bound to a model.
:::

## Standard-ability helpers

Shortcuts for the common verbs. Each takes the target and an optional route-group
closure:

```php
WarrantMiddleware::canView('documents');
WarrantMiddleware::canCreate('documents');
WarrantMiddleware::canUpdate('documents');
WarrantMiddleware::canDelete('documents');
WarrantMiddleware::canArchive('documents');
```

## Guarding a route group

`guard()` (and the helper closures) wrap a group of routes in one check:

```php
WarrantMiddleware::guard('documents', 'view', function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
});

// Same, via a helper:
WarrantMiddleware::canView('documents', function () {
    Route::get('/documents', [DocumentController::class, 'index']);
});
```

## Match modes

`string()` and `guard()` accept an `AbilityMatchMode`. The mode segment is only
added to the middleware string when it isn't the default `ALL`:

```php
use Warrant\AbilityMatchMode;

WarrantMiddleware::string('document', ['view', 'approve'], AbilityMatchMode::ANY);
// -> warrant:document,any,view,approve
```

## Reachability guards

Alongside the row-level checks, the [reachability](/guides/reachability/) questions
have matching guards — gate a section by whether the user _could ever_ act, or
short-circuit a route to those who provably never can:

```php
// Reachable only if the user could ever view a document — otherwise 403:
Route::get('/documents', ...)->middleware(WarrantMiddleware::couldEver('documents', 'view'));

// Only when the ability is guaranteed by the rules' shape:
WarrantMiddleware::always('documents', 'create', fn () => Route::post('/documents', ...));

// Only when the user provably never can (e.g. an upsell page):
Route::get('/upgrade', ...)->middleware(WarrantMiddleware::never('documents', 'approve'));
```

These are **target-free** — the first argument is always a schema key (or a
schema/model class), never a route parameter, because reachability has no row to
bind. Unlike `warrant:`, the mode and match mode live in the middleware **alias**
rather than the parameters, so everything after the colon is just the schema key
and abilities (and an ability may safely be named `any` or `all`):

```
warrant.could-ever:documents,view          warrant.could-ever.any:documents,view,approve
warrant.always:documents,view              warrant.always.any:documents,view,approve
warrant.never:documents,view               warrant.never.any:documents,view,approve
```

## How resolution works

When the middleware runs, it:

1. Tries to resolve the target as a **schema key** (no-model path).
2. If that fails, treats the target as a **route parameter name**, resolves it to
   a model, and finds the schema from the model's class.
3. Parses the segment after the target: `all` / `any` becomes the match mode;
   anything else is the first ability.
4. Calls [`authorize`](/guides/denial-messages/) — the throwing check — aborting
   **403** when the user is unauthenticated or lacks the abilities. Because it goes
   through `authorize`, a denial on a model-bound route carries the responsible
   rule's [denial message](/guides/denial-messages/) instead of a bare status.

:::caution[Targeted middleware doesn't pass a `context:` array]
The middleware calls `authorize` **without** a context argument, so rules that
reference `@context` keys behind a middleware gate must get those values from
[`defaultContext()`](/guides/context/#defaults). A required context key with no
default will make the gated route throw.
:::
