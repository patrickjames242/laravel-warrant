---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Middleware
description: WarrantMiddleware, the reachability guards, and the aliases they register.
sidebar:
  order: 8
---

`Warrant\Middleware\WarrantMiddleware`. Conceptual coverage is in
[middleware](/checking/middleware/).

The `$target` is either a **schema key**, for a no-row check, or a **route
parameter name** bound to a model, for a targeted check.

## Building middleware strings

```php
public static function string(
    string $target,
    string|array $abilities,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): string;
```

```php
WarrantMiddleware::string('document', 'view');
// -> "warrant:document,view"

WarrantMiddleware::string('document', ['view', 'approve'], AbilityMatchMode::ANY);
// -> "warrant:document,any,view,approve"
```

The match-mode segment is inserted only when it is not the default `ALL`.

## Guarding a route group

```php
public static function guard(
    string $target,
    string|array $abilities,
    Closure $routes,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): void;
```

## Standard-ability helpers

With a closure they guard a group; without one they return the middleware string.

```php
public static function canView(string $target, ?Closure $routes = null): ?string;
public static function canCreate(string $target, ?Closure $routes = null): ?string;
public static function canUpdate(string $target, ?Closure $routes = null): ?string;
public static function canDelete(string $target, ?Closure $routes = null): ?string;
public static function canArchive(string $target, ?Closure $routes = null): ?string;
```

Each maps to the matching
[`StandardAbilities`](/reference/warrant-schema/#standardabilities) constant.

## Reachability guards

Backed by the [reachability](/checking/reachability/) system, which evaluates no
row or global condition and runs no SQL.

```php
public static function couldEver(
    string $target,
    string|array $abilities,
    ?Closure $routes = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): ?string;   // passes when reachability !== NEVER

public static function always(...): ?string;   // passes when === ALWAYS
public static function never(...): ?string;    // passes when === NEVER
```

The aliases are `warrant.could-ever`, `warrant.always`, and `warrant.never`, each
with an `.any` variant:

```text
warrant.could-ever:documents,view       warrant.could-ever.any:documents,view,approve
warrant.always:documents,view           warrant.always.any:documents,view,approve
warrant.never:documents,view            warrant.never.any:documents,view,approve
```

:::note[The mode lives in the alias]
Unlike `warrant:`, the guard kind and match mode are baked into the alias name, so
the parameters are only the schema key and abilities. That is why an ability may
safely be named `any` or `all`.
:::

These guards are target-free. `$target` is a schema key or a schema or model class,
never a route parameter, and the schema is resolved by key only.

## The handler

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

1. resolves `$target` as a schema key;
2. failing that, treats it as a route parameter name, resolves it to a model
   instance, and finds the schema from the model's class;
3. reads the segment after the target, where `all` or `any` is the match mode and
   anything else is the first ability;
4. calls `authorize`, which throws
   [`WarrantAuthorizationException`](/reference/exceptions/), rendered as a 403
   carrying the responsible rule's denial message.

## `AbstractReachabilityMiddleware`

The base for the six reachability guards. Each concrete subclass fixes two things,
which is what lets the alias carry them:

```php
abstract protected function passes(Reachability $reachability): bool;
protected function matchMode(): AbilityMatchMode;   // default ALL
```

Subclasses: `CouldEverMiddleware`, `CouldEverAnyMiddleware`, `AlwaysMiddleware`,
`AlwaysAnyMiddleware`, `NeverMiddleware`, `NeverAnyMiddleware`.

Extend it if you need a guard with different semantics, such as one passing on
`MAYBE` alone.

## Errors

| Condition | Exception |
|---|---|
| no abilities supplied (`warrant:`) | `InvalidArgumentException`, *Access control middleware requires at least one ability.* |
| no abilities (reachability guards) | `InvalidArgumentException`, *Warrant reachability middleware requires at least one ability.* |
| route parameter is not a model | `InvalidArgumentException`, *must resolve to a model instance.* |
| target resolves to no schema (`warrant:`) | `InvalidArgumentException`, *Unable to resolve access control schema for [...]* |
| target is not a schema key (reachability guards) | `InvalidArgumentException`, *reachability guards take a schema key.* |
| unauthenticated or unauthorized | `WarrantAuthorizationException`, 403 |

:::caution[No context on targeted checks]
The middleware calls `authorize` without a context array, so rules behind a gate
that reference `@context` keys must get those values from
[`defaultContext()`](/concepts/context/supplying/).
:::
