---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Checking API
description: The authorization engine — the Warrant facade, WarrantGuard, WarrantGuardForSchema — and the HasWarrantSchema query helpers.
sidebar:
  order: 4
---

Reference for the checking surface: the `Warrant` facade, the two guard objects
(`WarrantGuard`, `WarrantGuardForSchema`), the ergonomic entry points, and the
`HasWarrantSchema` query-time helpers. Conceptual coverage is in
[Checking access](/guides/checking-access/).

Every check runs on the **authorization engine**, bound to a user (and, at the
inner layer, a schema). There is no longer any static check method on the model
or the schema — reach the engine one of the ways below. `$user` is always
optional and defaults to `auth()->user()` (an `InvalidArgumentException` is thrown
if none is available).

## Reaching the engine

```php
// 1. Facade — schema-less; the target names the schema.
Warrant::can('update', $document);

// 2. User-bound guard (WarrantGuard).
Warrant::guard($user)->can('update', $document);
$user->warrant()->can('update', $document);          // AuthorizesWithWarrant trait

// 3. Schema-bound guard (WarrantGuardForSchema).
Warrant::forSchema($document, $user)->can('update', $document);
DocumentSchema::guard($user)->can('update', $document); // static on the schema
```

### `AuthorizesWithWarrant` (user trait)

Add to your `User` model to reach that user's engine directly.

```php
use Warrant\AuthorizesWithWarrant;

class User extends Authenticatable
{
    use AuthorizesWithWarrant;
}

public function warrant(): WarrantGuard;   // === Warrant::guard($this)
```

### `WarrantSchema::guard()` (static)

Every schema inherits this static shortcut for its own schema-bound guard.

```php
public static function guard(?Authenticatable $user = null): WarrantGuardForSchema;
// === Warrant::forSchema(static::class, $user)
```

## The `Warrant` facade

Schema-less: the **target** names the schema (and, optionally, the row).

```php
Warrant::guard(?Authenticatable $user = null): WarrantGuard;
Warrant::forSchema(Model|WarrantSchema|string $schema, ?Authenticatable $user = null): WarrantGuardForSchema;

Warrant::can(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool;      // ALL
Warrant::canAny(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool;   // ANY
Warrant::cannot(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool;
Warrant::authorize(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): void;      // throws 403 (ALL)
Warrant::authorizeAny(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): void;   // throws 403 (ANY)
Warrant::abilities(Model|string|array $target, array $context = [], ?Authenticatable $user = null): array;
```

- **There is no `matchMode:` argument.** ALL is `can`; ANY is `canAny`. Likewise
  `authorize` / `authorizeAny`.
- `authorize()` / `authorizeAny()` are the **throwing siblings** — they return
  `void` and throw
  [`WarrantAuthorizationException`](/reference/errors/#authorization-failures--warrantauthorizationexception)
  (rendered as HTTP **403**) on denial, carrying a diagnosed denial context (see
  [Denial messages](/guides/denial-messages/)).
- `context:` is a separate argument, merged over the schema's `defaultContext()`.

### Target forms

The `target` on the facade / `WarrantGuard` names the schema and, optionally, a row:

```php
Warrant::can('update', $document);                  // Model instance — the row
Warrant::can('update', [Document::class, $id]);     // [class, id] — a row by key
Warrant::can('create', Document::class);            // model class — no-target
Warrant::can('create', DocumentSchema::class);      // schema class — no-target
Warrant::can('create', 'documents');                // schema key — no-target
```

## `WarrantGuard` (user-bound)

Reached with `Warrant::guard($user)` or `$user->warrant()`. Same target forms as
the facade; the user is fixed.

```php
public function forSchema(Model|WarrantSchema|string $schema): WarrantGuardForSchema;

public function can(string|array $abilities, Model|string|array $target, array $context = []): bool;
public function canAny(string|array $abilities, Model|string|array $target, array $context = []): bool;
public function cannot(string|array $abilities, Model|string|array $target, array $context = []): bool;
public function authorize(string|array $abilities, Model|string|array $target, array $context = []): void;
public function authorizeAny(string|array $abilities, Model|string|array $target, array $context = []): void;
public function abilities(Model|string|array $target, array $context = []): array;
```

Reachability methods (schema-first) are listed under [Reachability](#reachability).

## `WarrantGuardForSchema` (schema + user-bound)

Reached with `Warrant::forSchema($schemaOrModel, $user)`, `DocumentSchema::guard($user)`,
or `$user->warrant()->forSchema(...)`. The schema is fixed, so the **target is
just the row** (or `null` for a no-target check).

```php
public function can(string|array $abilities, Model|string|null $target = null, array $context = []): bool;
public function canAny(string|array $abilities, Model|string|null $target = null, array $context = []): bool;
public function cannot(string|array $abilities, Model|string|null $target = null, array $context = []): bool;
public function authorize(string|array $abilities, Model|string|null $target = null, array $context = []): void;
public function authorizeAny(string|array $abilities, Model|string|null $target = null, array $context = []): void;
public function abilities(Model|string|null $target = null, array $context = []): array;

public function schema(): WarrantSchema;
public function user(): Authenticatable;
```

### Lower-level query builders

The `HasWarrantSchema` scopes below delegate to these; call them directly when you
hold a raw query builder. These **do** take an `AbilityMatchMode`.

```php
public function filterQuery(
    Builder $query,
    string $targetSqlId,
    string|array $abilities,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
): Builder;

public function selectAbilitiesInQuery(
    Builder $query,
    string $targetSqlId,
    string $selectedAbilitiesKey = 'abilities',
    ?array $onlyAbilities = null,
    array $context = [],
): Builder;

public function getAbilitiesWithoutTarget(
    string|array|null $abilities = null,   // null enumerates every held ability
    AbilityMatchMode $matchMode = AbilityMatchMode::ANY,
    array $context = [],
): array;
```

`getAbilitiesWithoutTarget()` defaults to `ANY` — the one exception to the `ALL`
default. For the common case, prefer `Warrant::abilities(Document::class)` /
`->abilities()`.

## `HasWarrantSchema` — model query helpers

The model trait no longer carries any static check methods. What remains are the
query-time conveniences that belong on the model.

```php
use Warrant\HasWarrantSchema;

class Document extends Model
{
    use HasWarrantSchema;

    public function warrantSchema(): string
    {
        return \App\Warrant\DocumentSchema::class;
    }
}
```

The trait declares `warrantSchema(): string` abstract. The returned schema's
`const model` must equal this model's class, or Warrant throws `LogicException`
(`Schema [...] must manage model [...]`) when a scope resolves the schema.

### Query scopes

These **keep** `AbilityMatchMode` (the query layer supports both modes directly).

```php
// ->userHasAbility(...)
public function scopeUserHasAbility(
    EloquentBuilder $query,
    string|array $abilities,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
): EloquentBuilder;

// ->selectUserAbilities(...)
public function scopeSelectUserAbilities(
    EloquentBuilder $query,
    ?Authenticatable $user = null,
    string $selectedAbilitiesKey = 'abilities',
    ?array $onlyAbilities = null,
    array $context = [],
): EloquentBuilder;
```

### Instance method

```php
public function loadUserAbilities(
    ?Authenticatable $user = null,
    string $selectedAbilitiesKey = 'abilities',
    array $context = [],
): array; // computes, then setAttribute() on the instance
```

- `$user` defaults to `auth()->user()`; scopes throw `LogicException` if no user
  is available.
- `userHasAbility([])` (empty ability list) leaves the query unchanged.
- `selectUserAbilities` targets rows via `getQualifiedKeyName()`. Its JSON column is
  in [ability declaration order](/guides/schemas/#abilities) and requires a
  supported [DB driver](/guides/how-it-compiles/#per-row-aggregation-is-driver-specific).

### The global scope

`Warrant\SelectUserAbilitiesScope` implements `Illuminate\Database\Eloquent\Scope`.
Its `apply()` no-ops when there's no authenticated user or the model lacks a
`warrantSchema()` method; otherwise it calls `selectUserAbilities($currentUser)`.

:::note
The trait does **not** register this scope automatically. Attach it yourself
(e.g. in the model's `booted()`) if you want the `abilities` column on every query.
:::

## Laravel Gate

When `register_gate` is `true` (the default), Warrant registers a `Gate::before`
hook so its abilities resolve through Laravel's native surfaces:

```php
$user->can('view', $document);                          // targeted row check
$user->can('approve', [$document, ['region' => 'us']]); // targeted + context
$user->can('create', Document::class);                  // no-target via model class
$user->can('create', [Document::class, ['region' => 'us']]); // no-target + context
Gate::authorize('view', $document);                     // throws Warrant's 403 + message
```

`@can`, `@cannot`, and the `can:` route middleware go through the same hook.
ALL/ANY across several abilities is native Laravel — `can([...])` / `canAny([...])`.
Abilities not declared by a registered schema return `null` from the hook and fall
through to your own policies. Guests (unauthenticated) always fall through. See
[Checking access → Laravel's Gate](/guides/checking-access/#laravels-gate).

The Gate arg convention accepts the tuple `[$model, ['ctx' => …]]` for context;
the facade/guard helpers take `context:` as their own argument instead.

## Match modes

```php
Warrant\AbilityMatchMode::ALL; // every listed ability required
Warrant\AbilityMatchMode::ANY; // any one is enough
```

`AbilityMatchMode` is used by the query scopes, the lower-level query/reachability
methods, and the middleware. The facade/guard **check** helpers express it through
the method name instead (`can` vs `canAny`, `authorize` vs `authorizeAny`).

## Reachability

Reachability answers *"could this user ever have this ability, given the shape of
the rules?"* — a purely **structural** analysis of the resolved rule set. It
evaluates **no conditions** and runs **no SQL**. Conceptual coverage is in
[Reachability](/guides/reachability/).

On the facade / `WarrantGuard` the schema comes **first**; there is no `matchMode`
(use the `*Any` variants for ANY) and no `context:` (conditions are never
evaluated), but a `$user` is still required — the resolver may return a different
rule set per user.

```php
Warrant::reachabilityOf(Model|WarrantSchema|string $schema, string $ability, ?Authenticatable $user = null): Reachability;

Warrant::couldEverHave($schema, string|array $abilities, ?Authenticatable $user = null): bool;      // all !== NEVER
Warrant::couldEverHaveAny($schema, string|array $abilities, ?Authenticatable $user = null): bool;
Warrant::alwaysHas($schema, string|array $abilities, ?Authenticatable $user = null): bool;          // all === ALWAYS
Warrant::alwaysHasAny($schema, string|array $abilities, ?Authenticatable $user = null): bool;
Warrant::neverHas($schema, string|array $abilities, ?Authenticatable $user = null): bool;           // all === NEVER
Warrant::neverHasAny($schema, string|array $abilities, ?Authenticatable $user = null): bool;

Warrant::possibleAbilities($schema, ?Authenticatable $user = null): array;    // reachability !== NEVER
Warrant::guaranteedAbilities($schema, ?Authenticatable $user = null): array;  // === ALWAYS
Warrant::impossibleAbilities($schema, ?Authenticatable $user = null): array;  // === NEVER
```

`WarrantGuard` carries the same methods (schema-first). Example:
`Warrant::guard($user)->couldEverHave(Document::class, 'update')`.

`Warrant\Reachability` is a pure enum with cases `NEVER`, `MAYBE`, `ALWAYS`. See
[Schema API](/reference/schema-api/#reachability) for the per-ability decision table.

### On `WarrantGuardForSchema`

The schema is already bound, so no schema argument:

```php
public function reachabilityOf(string $ability): Reachability;
public function couldEverHave(string|array $abilities): bool;
public function couldEverHaveAny(string|array $abilities): bool;
public function alwaysHas(string|array $abilities): bool;
public function alwaysHasAny(string|array $abilities): bool;
public function neverHas(string|array $abilities): bool;
public function neverHasAny(string|array $abilities): bool;
public function possibleAbilities(): array;
public function guaranteedAbilities(): array;
public function impossibleAbilities(): array;

// building blocks the above are expressed in terms of:
public function reachabilityMap(?array $abilities = null): array;
public function reachabilitySatisfies(string|array $abilities, callable $passes, AbilityMatchMode $matchMode): bool;
public function abilitiesWhereReachability(callable $passes): array;
```
