---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: WarrantSchema
description: Constants, reflection, overridable hooks, and the condition context objects.
sidebar:
  order: 3
---

`Warrant\Schema\WarrantSchema`, abstract. A schema is pure definition: it declares
vocabulary and configuration and holds no user. Every user-scoped operation lives
on a [guard](/reference/warrant-guard/).

## Constants

```php
public const model = '';  // class-string of the managed Model; '' means no model
public const key = '';    // a virtualTable's identifying column; '' means none
```

`key` names the column a [`virtualTable()`](#virtualtable)'s rows are identified
by. A model answers this through `getKeyName()`, so declaring both is rejected.

## Static guard shortcut

```php
public static function guard(?Authenticatable $user = null): WarrantGuardForSchema;
// === Warrant::forSchema(static::class, $user)
```

## Reflection

```php
public static function schemaKey(): string;           // the config key; needs a booted app
public static function abilityNames(): array;         // declaration order, NOT sorted
public static function abilityDefinitions(): array;   // AbilityDefinition[] { name, requiredContext }
public static function getAbilityDefinition(string $abilityKey): ?AbilityDefinition;
public static function conditionKeys(): array;        // sorted
public static function rowConditionKeys(): array;     // sorted
public static function globalConditionKeys(): array;  // sorted
public static function ruleTemplateKeys(): array;
public static function requiredContextKeys(): array;  // schema-wide #[RequiredContext] values
public static function hasRows(): bool;
public static function hasRowKey(): bool;
```

`hasRows()` is true for a model or a virtual table. `hasRowKey()` is true when one
row can be named: a model, or a virtual table with `const key` or `matchKey()`.
Both are read by reflection, so asking never builds a query.

## Overridable hooks

```php
public static function virtualTable(): ?Builder;         // default null
public function implicitRules(): array|WarrantRuleSet;   // default []
protected function defaultContext(): array;              // default []

// Declared on your schema when needed; deliberately not inherited.
public function matchKey(RowConditionContext $c, ...): ?Builder;
```

### `matchKey`

How this schema's rows are addressed. Every row-bound reference goes through it: a
`can(... for <schema>(<args>))` or `check(...)` hop, and a targeted check from PHP,
with the caller's arguments bound positionally after the context.

Declare it only when rows are addressed by something other than their key.

```php
public function matchKey(RowConditionContext $c, mixed $tenant, mixed $slug): ?Builder
{
    return $c->query
        ->where($c->row('tenant_slug'), '=', $tenant)
        ->where($c->row('slug'), '=', $slug);
}
```

- **Arity comes from the parameters.** Those without a default are required; a
  handle supplying too few is rejected at validation. A variadic tail is never
  required, so a variadic key accepts any count, including none.
- **Type the parameters `mixed`.** A `@column` or `@sql` argument arrives as an
  `Illuminate\Database\Query\Expression`.
- **Returning `null` answers unknown**, which neither grants nor lifts a denial.
- **A key must identify at most one row.** Nothing enforces it.
- It is dispatched like a row condition, with the same context object and alias
  handling, and it is **not** part of the vocabulary. No rule can name it, and
  declaring `#[RowCondition]` on it is an error.

It is not declared on the base class because PHP forbids an override from adding
required parameters, which would make a multi-part key impossible to write.

### `virtualTable`

The query this schema's rows come from, when they are not a model's table.

```php
public static function virtualTable(): ?Builder
{
    return DB::table('teams')
        ->crossJoin('calendar_days')
        ->select(['teams.id as team_id', 'calendar_days.day']);
}
```

A schema draws its rows from one source or the other. Naming a `model` and
defining a `virtualTable()` is an error on first resolution.

A virtual-table schema gives up: Eloquent scopes, a hydrated `$c->model`, a key of
its own, and any model to reach it from. It takes no user and no context,
deliberately.

The compiler selects from it as a subquery aliased to the schema's key.

### Denial-message hooks

```php
public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null;
public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null;
```

Precedence, first non-null wins: the matching `cannot` rule's own message, then
`forbiddenDenialMessage()`, then `ungrantedDenialMessage()`, then a generic 403.

## Condition context objects

### `GlobalConditionContext` (readonly)

```php
public function __construct(
    public Authenticatable $user,
    public Builder $query,
    public array $arguments = [],
    public array $context = [],
);
```

### `RowConditionContext` (readonly)

```php
public function __construct(
    public Authenticatable $user,
    public Builder $query,
    public string $table,        // e.g. "documents"
    public string $keyColumn,    // e.g. "id"
    public array $arguments = [],
    public array $context = [],
    public ?Model $model = null,
);

public function row(?string $column = null): string;
// row() => "documents.id"; row('user_id') => "documents.user_id"
```

`model` is set only when the check named one specific hydrated row. It is `null`
when filtering, listing per-row abilities, or when the row was named by key or is
unsaved or deleted.

## Enums and helpers

### `AbilityMatchMode`

```php
AbilityMatchMode::ANY;   // 'any'
AbilityMatchMode::ALL;   // 'all'
```

Used by the query scopes, the lower-level query and reachability methods, and the
middleware. The check helpers express the mode through the method name.

### `Reachability`

A pure enum with cases `NEVER`, `MAYBE`, `ALWAYS`. Decision per ability, top to
bottom: an unconditional `cannot` gives `NEVER`; no `can` rule listing it gives
`NEVER`; an unconditional `can` with no *conditional* `cannot` gives `ALWAYS`;
otherwise `MAYBE`.

### `StandardAbilities`

```php
StandardAbilities::VIEW;     // 'view'
StandardAbilities::CREATE;   // 'create'
StandardAbilities::UPDATE;   // 'update'
StandardAbilities::DELETE;   // 'delete'
StandardAbilities::ARCHIVE;  // 'archive'
```
