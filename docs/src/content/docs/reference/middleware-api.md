---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Middleware API
description: WarrantMiddleware — string builders, group guards, and standard-ability helpers.
sidebar:
  order: 5
---

Reference for `Warrant\Middleware\WarrantMiddleware`. Conceptual coverage is in
[Route middleware](/guides/middleware/).

The `$target` throughout is either a **schema key** (no-model check)
or a **route parameter name** bound to a model (targeted check).

## Building middleware strings

```php
public static function string(
    string $target,
    string|array $abilities,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): string;
```

Produces a `warrant:...` middleware string, e.g. `warrant:documents,view`. The
match-mode segment (`any` / `all`) is inserted only when `$matchMode` isn't the
default `ALL`:

```php
WarrantMiddleware::string('document', 'view');
// -> "warrant:document,view"

WarrantMiddleware::string('document', ['view', 'approve'], AbilityMatchMode::ANY);
// -> "warrant:document,any,view,approve"
```

## Guarding a route group

```php
public static function guard(
    string $target,
    string|array $abilities,
    Closure $routes,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): void;
```

```php
WarrantMiddleware::guard('documents', 'view', function () {
    Route::get('/documents', [DocumentController::class, 'index']);
});
```

## Standard-ability helpers

Each takes the target and an optional route-group closure. With a closure they
guard the group; without one they return the middleware string.

```php
public static function canView(string $target, ?Closure $routes = null): ?string;
public static function canCreate(string $target, ?Closure $routes = null): ?string;
public static function canUpdate(string $target, ?Closure $routes = null): ?string;
public static function canDelete(string $target, ?Closure $routes = null): ?string;
public static function canArchive(string $target, ?Closure $routes = null): ?string;
```

`canView`…`canArchive` map to the matching
[`StandardAbilities`](/reference/schema-api/#standardabilities) constant.

## Reachability guards

Guards backed by the [reachability](/reference/checking-api/#reachability) system —
a purely structural check that runs no conditions and no SQL. Each returns the
middleware string when called without a `$routes` closure, or guards the group
when given one.

```php
public static function couldEver(
    string $target,
    string|array $abilities,
    ?Closure $routes = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): ?string; // passes when reachability !== NEVER

public static function always(
    string $target,
    string|array $abilities,
    ?Closure $routes = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): ?string; // passes when reachability === ALWAYS

public static function never(
    string $target,
    string|array $abilities,
    ?Closure $routes = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): ?string; // passes when reachability === NEVER
```

The aliases are `warrant.could-ever`, `warrant.always`, and `warrant.never`, each
with an `.any` variant for `AbilityMatchMode::ANY` (e.g. `warrant.could-ever.any`).

:::note[The mode lives in the alias, not the params]
Unlike the `warrant:` alias, the guard kind and match-mode are baked into the
**alias name**; the params are just `schemaKey,abilities...` —
e.g. `warrant.could-ever:documents,update` or
`warrant.always.any:documents,view,approve`.
:::

These guards are **target-free**: `$target` is a schema key or a schema/model
class, **never** a route parameter. The schema is resolved by **key only** (it
throws if the target isn't a key), and the middleware `abort(403)`s when the
reachability predicate fails. See [Reachability](/guides/reachability/).

## The middleware handler

```php
public function handle(
    Request $request,
    Closure $next,
    string $target,
    string $matchModeOrFirstAbility,
    string ...$remainingAbilities,
): Response;
```

At request time it:

1. Resolves `$target` as a **schema key** first.
2. Failing that, treats it as a **route parameter name**, resolves it to a model
   instance, and finds the schema from the model's class.
3. Reads the segment after the target: `all` / `any` is the match mode; anything
   else is the first ability.
4. Calls `authorize`, which throws
   [`WarrantAuthorizationException`](/reference/errors/#authorization-failures--warrantauthorizationexception)
   (rendered as **403**, carrying the responsible rule's denial message) if
   unauthenticated or unauthorized.

## Errors

| Condition | Exception |
|---|---|
| No abilities supplied (`warrant:`) | `InvalidArgumentException` — *"Access control middleware requires at least one ability."* |
| No abilities supplied (reachability guards) | `InvalidArgumentException` — *"Warrant reachability middleware requires at least one ability."* |
| Route parameter isn't a model instance | `InvalidArgumentException` — *"must resolve to a model instance."* |
| Target resolves to no schema (`warrant:`) | `InvalidArgumentException` — *"Unable to resolve access control schema for [...]"* |
| Target isn't a schema key (reachability guards) | `InvalidArgumentException` — *"Unable to resolve Warrant schema for [...]; reachability guards take a schema key."* |
| Unauthenticated / unauthorized | `WarrantAuthorizationException` (HTTP **403**) |

:::caution[No `context:` on targeted checks]
The middleware calls `authorize` without a context array, so rules behind
a middleware gate that reference `@context` keys must get those values from
[`defaultContext()`](/guides/context/#defaults).
:::
