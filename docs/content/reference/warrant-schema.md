---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schema classes
description: The schema classes, what each provides, their hooks, and the condition context objects.
sidebar:
  order: 3
---

A schema is pure definition: it declares vocabulary and configuration and holds no
user. Every user-scoped operation lives on a [guard](/reference/warrant-guard/).

## The classes

Extend one of three, by where the schema's rows come from. All live in
`Warrant\Schema\Types\Classes`.

| Class | Rows | Declare |
| --- | --- | --- |
| `ModelSchema` | A model's table | `public const model = Document::class;` |
| `QuerySchema` | A query's output | `rows()`, and `key()` or `matchKey()` |
| `RowlessSchema` | None | Nothing; its abilities are about no row |

`ModelSchema` and `QuerySchema` share `RowSchema`, and every schema extends
`Schema` through `AbilitySchema`. Those three hold what their children share, and
nothing extends them directly.

Constructing a schema checks what its class declares, once per class. A construct
its type does not provide fails there: a `#[RowCondition]` on a `RowlessSchema`,
say. A schema that declares a constructor of its own calls `parent::__construct()`.

## Every schema

```php
public function conditions(): array;                     // ConditionDefinition[], every kind
public function condition(string $key): ?ConditionDefinition;
public function globalConditions(): array;               // ConditionDefinition[]
public function derivedConditions(): array;              // ConditionDefinition[]
public function ruleTemplates(): array;                  // RuleTemplateDefinition[]
public function ruleTemplate(string $key): ?RuleTemplateDefinition;
```

Each list is read once per class, and a lookup by key is an array lookup.
Conditions of every kind share one set of names.

## Abilities, rules, context and denials

```php
public function abilities(): array;                      // AbilityDefinition[] { name, requiredContext }
public function abilityNames(): array;                   // declaration order, NOT sorted
public function ability(string $name): ?AbilityDefinition;
public function requiredContextKeys(): array;            // schema-wide #[RequiredContext] values

public static function guard(?Authenticatable $user = null): WarrantGuardForSchema;
// === Warrant::forSchema(static::class, $user)
```

### Overridable hooks

```php
public function rules(RuleProviderContext $context): mixed;   // default []; merged ahead of the provider's rules
public function defaultContext(): array;                      // default []
```

`rules()` answers with a rule set, a rule entry, a builder, rule text with or
without bindings, or an iterable of those.

### Denial-message hooks

```php
public function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null;
public function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null;
```

Precedence, first non-null wins: the matching `cannot` rule's own message, then
`forbiddenDenialMessage()`, then `ungrantedDenialMessage()`, then a generic 403.

## Rows

Every `ModelSchema` and `QuerySchema` has these:

```php
public function rowsQuery(bool $withoutScopes = false): Builder;  // a fresh query over the rows
public function rowName(): string;                                // what the rows are called in SQL
public function keyColumns(): array;                              // the column or columns one row is identified by
public function connection(): ConnectionInterface;
public function rowConditions(): array;                           // ConditionDefinition[]
public function rowMatcher(): ConditionDefinition;                // matchKey(), or the default over keyColumns()
```

### `matchKey`

How this schema's rows are addressed. Every row-bound reference goes through it: a
`can(... for <schema>(<args>))` or `check(...)` hop, and a targeted check from PHP,
with the caller's arguments bound positionally after the context.

Declare it only when rows are addressed by something other than equality on their
key columns.

```php
public function matchKey(RowConditionContext $c, mixed $tenant, mixed $slug): ?Builder
{
    return $c->query
        ->where($c->column('tenant_slug'), '=', $tenant)
        ->where($c->column('slug'), '=', $slug);
}
```

- **Arity comes from the parameters.** Those without a default are required; a
  handle supplying too few is rejected at validation and again when the row is
  named. A variadic tail is never required.
- **Type the parameters `mixed`.** A `@column` or `@sql` argument arrives as an
  `Illuminate\Database\Query\Expression`.
- **Returning `null` answers unknown**, which neither grants nor lifts a denial.
- **A key must identify at most one row.** Nothing enforces it.
- It is called with the same context object a row condition is, and it is
  **not** part of the vocabulary. No rule can name it, and declaring
  `#[RowCondition]` on it is an error.

It is not declared on the base class because PHP forbids an override from adding
required parameters, which would make a multi-part key impossible to write.

Without a `matchKey()`, a row is found by its key columns, each equal to the
argument in the same position. A `null` argument names no row and answers unknown.

## `ModelSchema`

```php
public const model = Document::class;

public static function model(): string;   // reads the constant; override instead to work it out
```

The model answers for the rows' table, key and connection. A model schema with no
`model` constant and no `model()` of its own fails when it is first constructed.
Row conditions get an Eloquent builder over the model, so they can use its scopes.

## `QuerySchema`

```php
public function rows(): Builder;                  // the query whose output is the rows
protected function key(): string|array|null;      // default null
```

`rows()` takes no user and no context, deliberately: it says what the rows are,
and the rules say who may touch them. It is called afresh wherever the rows are
needed.

`key()` names the column or columns one row is identified by. A query schema
declares `key()`, a `matchKey()`, or both, so every row can be found; one with
neither fails when it is first constructed.

A query schema gives up Eloquent scopes, a hydrated `$c->model`, and any model to
reach it from. The compiler selects from `rows()` as a subquery named
`warrant_rows`, or under the name a hop gives it.

## `RowlessSchema`

Abilities granted to the user as a whole, about no row: `can('export')`. It
answers only checks without a target, and its rules use global and derived
conditions.

## Interfaces

The engine reads schemas through interfaces, never the classes:

| Interface | Provided by |
| --- | --- |
| `ISchema` | every schema |
| `IAbilitySchema` | every schema a check is made against |
| `IRowSchema` | `ModelSchema` and `QuerySchema` |
| `IModelSchema` | `ModelSchema` |

Each combines interfaces for the constructs it provides: `IProvidesAbilities`,
`IProvidesConditions` and one per kind of condition, `IProvidesRuleTemplates`,
`IProvidesRules`, `IProvidesContext`, `IProvidesDenialMessages` and
`IProvidesRows`.

A schema in `config/warrant.php` must implement `ISchema`, and a check is made
only against an `IAbilitySchema`.

## `Vocabulary`

What rule text may say about one schema, as data. Validation and the language
server read this rather than a live schema.

```php
Vocabulary::of(ISchema $schema): Vocabulary;

public function ability(string $name): ?AbilityDefinition;
public function condition(string $key): ?ConditionDefinition;
public function ruleTemplate(string $key): ?RuleTemplateDefinition;
public function rowMatcher(): ?ConditionDefinition;   // null exactly when the schema has no rows
public function hasRows(): bool;
```

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
    public string $table,          // e.g. "documents"
    public array $keyColumns,      // e.g. ["id"]
    public array $arguments = [],
    public array $context = [],
    public ?Model $model = null,
);

public function column(?string $column = null): string;
// column() => "documents.id"; column('user_id') => "documents.user_id"
```

`column()` with no argument names the key, and needs a key of exactly one column.

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

A pure enum with cases `NEVER`, `MAYBE`, `ALWAYS`. Each ability is folded the way
the compiler does it (grants ORed, every deny negated and ANDed), over the values
each condition could still take. Row and global conditions could be anything,
derived conditions are read through, and `can(...)` references take the
reachability of the ability they name, on whichever schema they name. `NEVER` when
true is impossible, `ALWAYS` when only true is possible, otherwise `MAYBE`. See
[how an ability is judged](/concepts/reachability/#how-an-ability-is-judged).

### `StandardAbilities`

```php
StandardAbilities::VIEW;     // 'view'
StandardAbilities::CREATE;   // 'create'
StandardAbilities::UPDATE;   // 'update'
StandardAbilities::DELETE;   // 'delete'
StandardAbilities::ARCHIVE;  // 'archive'
```
