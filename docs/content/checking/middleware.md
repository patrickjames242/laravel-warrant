---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Middleware
description: Gating routes and route groups, with and without a bound model.
sidebar:
  order: 7
---

Warrant registers a `warrant` route middleware. You rarely write the string
yourself; build it with `Warrant\Middleware\WarrantMiddleware`.

:::note[Laravel's `can:` works too]
Because Warrant [bridges the Gate](/checking/gate/), `->middleware('can:view,document')`
resolves Warrant abilities. What the `warrant` middleware adds on top is no-row
checks by schema key, standard-ability helpers, route-group guards, and the
[reachability guards](/checking/reachability/).
:::

## Gating by a bound model

The route parameter has to resolve to a model instance, and Warrant finds its
schema from the model's class:

```php
Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->middleware(WarrantMiddleware::string('document', 'view'));
```

:::caution
A parameter that is a raw id with no route-model binding throws:

```text
route parameter [document] must resolve to a model instance.
```
:::

## Gating with no row

The first argument is a schema key:

```php
Route::post('/documents', [DocumentController::class, 'store'])
    ->middleware(WarrantMiddleware::canCreate('documents'));
```

`canCreate('documents')` produces the string `warrant:documents,create`.

## Standard-ability helpers

Each takes the target and an optional route-group closure:

```php
WarrantMiddleware::canView('documents');
WarrantMiddleware::canCreate('documents');
WarrantMiddleware::canUpdate('documents');
WarrantMiddleware::canDelete('documents');
WarrantMiddleware::canArchive('documents');
```

They map to the matching [`StandardAbilities`](/schemas/abilities/) constant, so
they only help if your schema uses those names.

## Guarding a group

```php
WarrantMiddleware::guard('documents', 'view', function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
});

WarrantMiddleware::canView('documents', function () {
    Route::get('/documents', [DocumentController::class, 'index']);
});
```

## Match modes

`string()` and `guard()` take an `AbilityMatchMode`. The mode segment is added only
when it is not the default `ALL`:

```php
use Warrant\AbilityMatchMode;

WarrantMiddleware::string('document', ['view', 'approve'], AbilityMatchMode::ANY);
// -> warrant:document,any,view,approve
```

## How resolution works

At request time the middleware:

1. tries to resolve the target as a schema key;
2. failing that, treats it as a route parameter name, resolves it to a model, and
   finds the schema from the model's class;
3. reads the segment after the target, where `all` or `any` is the match mode and
   anything else is the first ability;
4. calls `authorize`, aborting 403 when the user is unauthenticated or lacks the
   abilities.

Because it goes through `authorize`, a denial already carries the responsible
rule's [message](/rules/denial-messages/) rather than a bare status.

## The context problem

:::caution[Targeted middleware passes no context]
The middleware calls `authorize` with no context array. Rules behind a gate that
reference `@context` keys have to get those values from
[`defaultContext()`](/concepts/context/supplying/), and a required key with no
default makes the gated route throw.
:::

This is the single most common way a middleware-gated route breaks. If your
application is tenant-scoped, put the tenant in `defaultContext()` on every schema
and the problem never arises.

## Errors

| Condition | Exception |
|---|---|
| no abilities supplied | `InvalidArgumentException`, *Access control middleware requires at least one ability.* |
| no abilities on a reachability guard | `InvalidArgumentException`, *Warrant reachability middleware requires at least one ability.* |
| the parameter is not a model | `InvalidArgumentException`, *must resolve to a model instance.* |
| the target resolves to no schema | `InvalidArgumentException`, *Unable to resolve access control schema for [...]* |
| the target is not a key, on a reachability guard | `InvalidArgumentException`, *reachability guards take a schema key.* |
| unauthenticated or unauthorized | `WarrantAuthorizationException`, rendered 403 |
