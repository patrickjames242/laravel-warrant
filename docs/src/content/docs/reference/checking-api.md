---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Checking API
description: The HasWarrantSchema trait — model helpers, query scopes, and instance methods.
sidebar:
  order: 4
---

Reference for `Warrant\HasWarrantSchema`. Conceptual coverage is in
[Checking access](/guides/checking-access/).

## Setup

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

The trait declares `warrantSchema(): string` abstract, so every model using it
must implement the method and return a schema class-string.

The returned schema's `const model` must equal this model's class, or Warrant
throws `LogicException` (`Schema [...] must manage model [...]`). This check runs
when a query scope resolves the schema (`->userHasAbility(...)`,
`->selectUserAbilities(...)`); the static helpers delegate straight to the named
schema class and do not re-validate the model match.

## Static helpers

```php
public static function userHasAbilities(
    string|array $abilities,
    Model|string|null $target = null,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
): bool;

public static function getUserAbilities(
    Model|string|null $target = null,
    ?Authenticatable $user = null,
    array $context = [],
): array;

public static function authorize(
    string|array $abilities,
    Model|string|null $target = null,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
): void;
```

`authorize()` is the **throwing sibling** of `userHasAbilities()` — same
signature, but returns `void` and throws
[`WarrantAuthorizationException`](/reference/errors/#authorization-failures--warrantauthorizationexception)
(rendered as HTTP **403** by Laravel) on denial. The exception carries a
diagnosed denial context so you can attach a custom explanation — see
[Denial messages](/guides/denial-messages/).

## Instance methods

```php
public function userHasAbility(
    string|array $abilities,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
): bool; // one targeted EXISTS query on $this

public function loadUserAbilities(
    ?Authenticatable $user = null,
    string $selectedAbilitiesKey = 'abilities',
    array $context = [],
): array; // computes, then setAttribute() on the instance
```

## Query scopes

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

- `$user` defaults to `auth()->user()`; scopes throw `LogicException` if no user
  is available.
- `userHasAbility([])` (empty ability list) leaves the query unchanged.
- `selectUserAbilities` targets rows via `getQualifiedKeyName()`. Its JSON column is
  in [ability declaration order](/guides/schemas/#abilities) and requires a
  supported [DB driver](/guides/how-it-compiles/#per-row-aggregation-is-driver-specific).

## The global scope

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
$user->can('view', $document);                        // targeted row check
$user->can('approve', [$document, ['region' => 'us']]); // targeted + context
$user->can('create', Document::class);                // no-target via model class
$user->can('create', [Document::class, ['region' => 'us']]); // no-target + context
Gate::authorize('view', $document);                   // throws Warrant's 403 + message
```

`@can`, `@cannot`, and the `can:` route middleware go through the same hook.
ALL/ANY across several abilities is native Laravel — `can([...])` / `canAny([...])`.
Abilities not declared by a registered schema return `null` from the hook and fall
through to your own policies. Guests (unauthenticated) always fall through. See
[Checking access → Laravel's Gate](/guides/checking-access/#laravels-gate).

## Match modes

```php
Warrant\AbilityMatchMode::ALL; // default on trait helpers/scopes
Warrant\AbilityMatchMode::ANY;
```

The lower-level `getAbilitiesWithoutTarget()` (on the schema) defaults to `ANY` —
the one exception to the `ALL` default.

## Reachability

Reachability answers *"could this user ever have this ability, given the shape of
the rules?"* — a purely **structural** analysis of the resolved rule set. It
evaluates **no conditions** and runs **no SQL**. Conceptual coverage is in
[Reachability](/guides/reachability/).

```php
public static function abilityReachability(
    string $ability,
    ?Authenticatable $user = null,
): Reachability;

public static function userCouldEverHave(
    string|array $abilities,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): bool; // reachability !== NEVER

public static function userAlwaysHas(
    string|array $abilities,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): bool; // reachability === ALWAYS

public static function userNeverHas(
    string|array $abilities,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
): bool; // reachability === NEVER

public static function getUserPossibleAbilities(?Authenticatable $user = null): array;   // reachability !== NEVER
public static function getUserGuaranteedAbilities(?Authenticatable $user = null): array; // === ALWAYS
public static function getUserImpossibleAbilities(?Authenticatable $user = null): array;  // === NEVER
```

`Warrant\Reachability` is a pure enum with cases `NEVER`, `MAYBE`, `ALWAYS`. See
[Schema API](/reference/schema-api/#reachability) for the enum and the per-ability
decision table.

:::note[No `context:` argument — but a user is still required]
No reachability API takes a `context:` array; conditions are never evaluated, so
context is irrelevant. A `$user` is still required (defaulting to
`auth()->user()`) because the resolver may return a different rule set per user.
:::

### Lower-level instance methods

Defined on the schema instance; the static helpers above are thin wrappers over
these.

```php
public function reachabilityOf(Authenticatable $currentUser, string $ability): Reachability;
public function reachabilityMap(Authenticatable $currentUser, ?array $abilities = null): array;
public function reachabilitySatisfies(
    Authenticatable $currentUser,
    string|array $abilities,
    callable $passes,
    AbilityMatchMode $matchMode,
): bool;
public function abilitiesWhereReachability(Authenticatable $currentUser, callable $passes): array;
```
