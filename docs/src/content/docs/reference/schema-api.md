---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schema API
description: WarrantSchema, its attributes, reflection methods, and the condition context objects.
sidebar:
  order: 2
---

Reference for `Warrant\Schema\WarrantSchema` and the attributes and context
objects that go with it. Conceptual coverage is in [Schemas](/guides/schemas/)
and [Conditions](/guides/conditions/).

## `WarrantSchema` (abstract)

### Constants

```php
public const model = '';        // class-string of the managed Model; '' = no model
public const schemaKey = null;  // explicit key override; null = derive from model table
```

### Static entry points

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

public static function getNoTargetAbilitiesBag(?Authenticatable $user = null): array;
```

- `$user` defaults to `auth()->user()`; these entry points throw
  `InvalidArgumentException` if no user is available.
- `$target` may be a `Model` (its `getKey()` is used) or a scalar id. `null` uses
  the no-target path.
- `authorize()` mirrors `userHasAbilities()` but returns `void` and throws
  [`WarrantAuthorizationException`](/reference/errors/#authorization-failures--warrantauthorizationexception)
  (HTTP **403**) on denial. See [Denial messages](/guides/denial-messages/).

### Reflection

```php
public static function schemaKey(): string;            // const or (new model)->getTable()
public static function declaredAbilities(): array;     // declaration order (NOT sorted)
public static function conditionKeys(): array;         // sorted
public static function rowConditionKeys(): array;      // sorted
public static function globalConditionKeys(): array;   // sorted
public static function declaredContextKeys(): array;   // declaration order
public static function requiredContextKeys(): array;
```

### Overridable hooks

```php
protected function implicitRules(): array|WarrantRuleSet;   // default []; merged into every rule set
protected function defaultContext(): array;  // default []; merged UNDER explicit context
```

### Denial-message hooks

Schema-level fallbacks that supply a message when `authorize()` denies and the
responsible rule carried no `withDenialMessage()`. Override in your schema; each
returns `string|Throwable|null` (return `null` to fall through). See
[Denial messages](/guides/denial-messages/).

```php
protected function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null;   // a matching `cannot` denied, but carried no message
protected function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null; // nothing granted the ability
```

Message-source precedence (first non-null wins): (1) the matching `cannot` rule's
`withDenialMessage()`; (2) `forbiddenDenialMessage()`; (3) `ungrantedDenialMessage()`;
(4) a generic 403.

### Reachability

Structural analysis of the resolved rule set — evaluates no conditions, runs no
SQL, takes no `context:`. See [Checking API](/reference/checking-api/#reachability)
for full signatures and [Reachability](/guides/reachability/) for concepts.

```php
public static function abilityReachability(string $ability, ?Authenticatable $user = null): Reachability;
public static function userCouldEverHave(string|array $abilities, ?Authenticatable $user = null, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): bool;
public static function userAlwaysHas(string|array $abilities, ?Authenticatable $user = null, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): bool;
public static function userNeverHas(string|array $abilities, ?Authenticatable $user = null, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): bool;
public static function getUserPossibleAbilities(?Authenticatable $user = null): array;
public static function getUserGuaranteedAbilities(?Authenticatable $user = null): array;
public static function getUserImpossibleAbilities(?Authenticatable $user = null): array;
```

## Attributes

### `#[Ability]`

Marks a class constant as an ability. No arguments. The constant's **value** is
the ability name; its name is ignored (discovery is by attribute).

```php
#[Ability] public const VIEW = 'view';
```

### `#[RowCondition]` / `#[GlobalCondition]`

Mark a public method as a condition. Optional key overrides the snake-cased method
name; passing `''` throws.

```php
#[RowCondition]              // key = snake_case(method)
#[RowCondition('is_owner')]  // explicit key
#[GlobalCondition]
```

The method's **first** parameter is the context object, typed to match:
`RowConditionContext` or `GlobalConditionContext`. Any parameters after it receive
the condition's DSL arguments positionally (parameter #2 → `argument[0]`, and so
on); a variadic tail collects the rest, and a parameter with a default is optional.

A condition may only add **where clauses** to `$c->query` (including `whereExists`,
`whereIn`, `whereRaw`). Emitting a `join`, `groupBy`, `having`, aggregate, or
`union` throws at compile time — use a correlated `whereExists()`/`whereNotExists()`
subquery to reach another table. A `#[GlobalCondition]` may instead return a
`bool`, evaluated in PHP.

### `#[ContextKey]`

Marks a class constant as a check-time context key. `required` defaults to `true`.
The constant's **value** is the key string.

```php
#[ContextKey] public const WORKSPACE = 'workspace_id';
#[ContextKey(required: false)] public const AS_OF = 'as_of_date';
```

## Condition context objects

### `GlobalConditionContext` (readonly)

```php
public function __construct(
    public Authenticatable $user,
    public Builder $query,       // Illuminate query builder
    public array $arguments = [],
    public array $context = [],
);
```

### `RowConditionContext` (readonly)

Same as above, plus the target row's `table` and `keyColumn`, and a `row()` helper
that qualifies a column against the target table (always present for a row condition):

```php
public function __construct(
    public Authenticatable $user,
    public Builder $query,
    public string $table,        // e.g. "documents"
    public string $keyColumn,    // e.g. "id"
    public array $arguments = [],
    public array $context = [],
);

public function row(?string $column = null): string; // row() => "documents.id"; row('user_id') => "documents.user_id"
```

## Enums & helpers

### `AbilityMatchMode`

```php
AbilityMatchMode::ANY; // 'any' — any one ability is enough
AbilityMatchMode::ALL; // 'all' — every listed ability required
```

### `Reachability`

Pure enum (not backed) returned by the [reachability](#reachability) API.

```php
Reachability::NEVER;  // no rule shape can ever grant it
Reachability::MAYBE;  // grantable, but subject to conditions at check time
Reachability::ALWAYS; // granted by the rules' shape (NOT a per-row guarantee)
```

Decision per ability, top to bottom: (1) an unconditional `cannot` → `NEVER`;
(2) no `can` rule lists it → `NEVER`; (3) an unconditional `can` and no
*conditional* `cannot` → `ALWAYS`; (4) otherwise → `MAYBE`. A **conditional**
`cannot` is intentionally ignored — `ALWAYS` means "granted by the rules' shape",
not a guarantee for every row.

### `StandardAbilities`

```php
StandardAbilities::VIEW;    // 'view'
StandardAbilities::CREATE;  // 'create'
StandardAbilities::UPDATE;  // 'update'
StandardAbilities::DELETE;  // 'delete'
StandardAbilities::ARCHIVE; // 'archive'
```

## The `Warrant` facade / `WarrantManager`

The `Warrant` facade exposes the schema registry:

```php
Warrant::getSchemaForModelClass(string $modelClass): string;
Warrant::getSchemaForKey(string $schemaKey): string;
Warrant::resolveSchemaKey(Model|WarrantSchema|string $schema): string;
Warrant::getNoTargetAbilitiesBag(?Authenticatable $user = null, string ...$schemaClassesOrSchemaKeys): array;
Warrant::registeredSchemas(): array;
```

Of these, only `resolveSchemaKey()` accepts a model instance or model class;
`getNoTargetAbilitiesBag()` and the reachability proxies below take a schema key
or a schema class-string (a model class is **not** accepted and throws
`OutOfBoundsException`).

It also proxies the [reachability](#reachability) statics; the first argument is a
schema key or a schema class-string:

```php
Warrant::abilityReachability(string $schemaClassOrKey, string $ability, ?Authenticatable $user = null): Reachability;
Warrant::userCouldEverHave(string $schemaClassOrKey, string|array $abilities, ?Authenticatable $user = null, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): bool;
Warrant::userAlwaysHas(string $schemaClassOrKey, string|array $abilities, ?Authenticatable $user = null, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): bool;
Warrant::userNeverHas(string $schemaClassOrKey, string|array $abilities, ?Authenticatable $user = null, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): bool;
// e.g. Warrant::userCouldEverHave('documents', 'update', $user)
```

Unknown key/schema lookups throw `OutOfBoundsException`.
