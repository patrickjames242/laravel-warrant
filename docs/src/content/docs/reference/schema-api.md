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

A schema is **pure definition** — it declares vocabulary and configuration and
holds no user. Every user-scoped operation (checks, ability listing, query
filtering, denial diagnosis, reachability) lives on the engine; see the
[Checking API](/reference/checking-api/). The only user-facing entry point on the
schema itself is the static `guard()` shortcut.

## `WarrantSchema` (abstract)

### Constants

```php
public const model = '';  // class-string of the managed Model; '' = no model
```

### Static guard shortcut

```php
public static function guard(?Authenticatable $user = null): WarrantGuardForSchema;
// === Warrant::forSchema(static::class, $user)
```

Every concrete schema inherits it: `DocumentSchema::guard($user)->can('view', $document)`.
See the [Checking API](/reference/checking-api/) for the returned guard's methods.

### Reflection

```php
public static function schemaKey(): string;          // the config key; needs a booted app
public static function abilityNames(): array;        // declaration order (NOT sorted)
public static function abilityDefinitions(): array;  // AbilityDefinition[] { name, requiredContext }
public static function getAbilityDefinition(string $abilityKey): ?AbilityDefinition;
public static function conditionKeys(): array;        // sorted
public static function rowConditionKeys(): array;     // sorted
public static function globalConditionKeys(): array;  // sorted
public static function requiredContextKeys(): array;  // schema-wide required keys (#[RequiredContext])
```

### Overridable hooks

```php
public function implicitRules(): array|WarrantRuleSet;   // default []; merged into every rule set
protected function defaultContext(): array;              // default []; merged UNDER explicit context
```

### Denial-message hooks

Schema-level fallbacks that supply a message when `authorize()` denies and the
responsible rule carried no `withDenialMessage()`. Override in your schema; each
returns `string|Throwable|null` (return `null` to fall through). See
[Denial messages](/guides/denial-messages/).

```php
public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null;   // a matching `cannot` denied, but carried no message
public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null; // nothing granted the ability
```

Message-source precedence (first non-null wins): (1) the matching `cannot` rule's
`withDenialMessage()`; (2) `forbiddenDenialMessage()`; (3) `ungrantedDenialMessage()`;
(4) a generic 403.

### Reachability

Structural analysis of the resolved rule set — evaluates no conditions, runs no
SQL, takes no `context:`. It lives on the engine, not the schema: see
[Checking API → Reachability](/reference/checking-api/#reachability) for the full
surface (`Warrant::reachabilityOf`, `couldEverHave`, `alwaysHas`, `neverHas`,
`possibleAbilities`, …) and [Reachability](/guides/reachability/) for concepts.

## Attributes

### `#[Ability]`

Marks a class constant as an ability. The constant's **value** is the ability
name; its name is ignored (discovery is by attribute).

```php
#[Ability] public const VIEW = 'view';
```

An optional `requiredContext` names context keys that must be present whenever
**this** ability is checked. A yes/no check (`can` / `authorize` / `@can`) throws
if a key is missing; enumeration (`abilities` / `selectUserAbilities`) skips the
ability instead.

```php
#[Ability(requiredContext: ['workspace_id'])] public const PUBLISH = 'publish';
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
`whereIn`, `whereRaw`), and must add **at least one**. Emitting a `join`, `groupBy`,
`having`, aggregate, or `union` throws at compile time — use a correlated
`whereExists()`/`whereNotExists()` subquery to reach another table. Returning the
query untouched throws too, since it would silently mean "match every row"; return
`true` to mean that. A `#[GlobalCondition]` may instead return a `bool`, evaluated
in PHP.

### `#[RequiredContext]`

Marks a class constant's **value** as a context key that is required on **every**
check against the schema. Any check whose effective context (explicit or from
`defaultContext()`) omits the key throws up front.

```php
#[RequiredContext] public const WORKSPACE = 'workspace_id';
```

Context keys do **not** need declaring to be *used* — a rule may reference any
`@context <key>` and a condition may read `$c->context['<key>']` freely. This
attribute is only about making a key mandatory schema-wide; for a key mandatory
only when a particular ability is checked, use `#[Ability(requiredContext: [...])]`.
See [Context](/guides/context/).

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

Used by the query scopes, the lower-level query/reachability methods, and the
middleware. The facade/guard check helpers express the mode through the method name
instead (`can` vs `canAny`).

### `Reachability`

Pure enum (not backed) returned by the [reachability](/reference/checking-api/#reachability) API.

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

The facade's full check and reachability surface is documented in the
[Checking API](/reference/checking-api/). For schema resolution it exposes the
registry:

```php
Warrant::registry(): SchemaRegistry;
```

`SchemaRegistry` normalizes any accepted reference — a model class or instance, a
schema key, a schema class or instance, or `null` — to a coordinate. Each
coordinate has an `OrNull` resolver (returns `null` for a null/unregistered
reference) and an `OrFail` resolver (throws `OutOfBoundsException` instead; a
`$passThroughNull` flag lets a null reference pass back as null while a non-null
still throws):

```php
Warrant::registry()->resolveSchemaClassOrNull(Model|WarrantSchema|string|null $ref): ?string;
Warrant::registry()->resolveSchemaClassOrFail(Model|WarrantSchema|string|null $ref, bool $passThroughNull = false): ?string;
Warrant::registry()->resolveModelOrNull(...): ?string;
Warrant::registry()->resolveModelOrFail(...): ?string;
Warrant::registry()->resolveSchemaKeyOrNull(...): ?string;
Warrant::registry()->resolveSchemaKeyOrFail(...): ?string;
Warrant::registry()->registeredSchemas(): array;
```

A `WarrantSchema` (class or instance) resolves to itself, but must be registered —
an unregistered schema has no schema key, so nothing can name it in rule syntax or
in a `RuleResolutionContext`. A model reference resolves through the model's own
`HasWarrantSchema::warrantSchema()`. A bare string is treated as a literal schema
key and is returned unchanged by the `resolveSchemaKey*` pair, so rule syntax still
parses and writes without a registry; it is `resolveSchemaClass*` that rejects an
unregistered key.

Two declarations describe the model↔schema link, and both are authoritative in one
direction: the schema's `const model`, and the model's `warrantSchema()`. The
registry cross-checks that they name each other the first time it resolves a
schema, and throws (`LogicException`) if they disagree, if the model does not use
the trait, or if the registered class is not a `WarrantSchema`. These checks are
deferred rather than run at boot because each one requires loading a class.

To list a user's no-target abilities for a schema, use
`Warrant::abilities(Document::class, $context, $user)` — see the
[Checking API](/reference/checking-api/).
