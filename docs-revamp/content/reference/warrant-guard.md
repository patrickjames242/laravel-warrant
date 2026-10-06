---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: WarrantGuard and WarrantGuardForSchema
description: The two bound guards, and the lower-level query builders on the schema-bound one.
sidebar:
  order: 2
---

## `WarrantGuard` (user-bound)

Reached with `Warrant::guard($user)` or `$user->warrant()`. Same target forms as the
facade; the user is fixed.

```php
public function forSchema(Model|WarrantSchema|string $schema): WarrantGuardForSchema;

public function can(string|array $abilities, Model|string|array $target, array $context = []): bool;
public function canAny(string|array $abilities, Model|string|array $target, array $context = []): bool;
public function cannot(string|array $abilities, Model|string|array $target, array $context = []): bool;
public function authorize(string|array $abilities, Model|string|array $target, array $context = []): void;
public function authorizeAny(string|array $abilities, Model|string|array $target, array $context = []): void;
public function abilities(Model|string|array $target, array $context = []): array;
```

It also carries the schema-first reachability methods:

```php
$user->warrant()->couldEverHave(Document::class, 'update');
$user->warrant()->possibleAbilities(Document::class);
```

## `WarrantGuardForSchema` (schema and user bound)

Reached with `Warrant::forSchema($schemaOrModel, $user)`, `DocumentSchema::guard($user)`,
or `$user->warrant()->forSchema(...)`. The schema is fixed, so the target is just
the row, and a key may be a string **or** an int.

```php
public function can(string|array $abilities, Model|string|int|null $target = null, array $context = []): bool;
public function canAny(string|array $abilities, Model|string|int|null $target = null, array $context = []): bool;
public function cannot(string|array $abilities, Model|string|int|null $target = null, array $context = []): bool;
public function authorize(string|array $abilities, Model|string|int|null $target = null, array $context = []): void;
public function authorizeAny(string|array $abilities, Model|string|int|null $target = null, array $context = []): void;
public function abilities(Model|string|int|null $target = null, array $context = []): array;

public function schema(): WarrantSchema;
public function user(): Authenticatable;
public function query(): Builder;
public function resolvedRuleSet(): RuleSetNode;
public function expandedRuleSet(): ExpandedRuleSet;   // blocks, includes and derived conditions expanded
```

```php
$guard->can('update', $document);
$guard->can('update', 42);
$guard->can('update', 'doc-9');
$guard->can('create');
```

### Query builders

The `HasWarrantSchema` scopes delegate to these. These **do** take an
`AbilityMatchMode`.

```php
public function filterQuery(
    Builder $query,
    string|array $abilities,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
): Builder;

public function selectAbilitiesInQuery(
    Builder $query,
    string $selectedAbilitiesKey = 'abilities',
    ?array $onlyAbilities = null,
    array $context = [],
): Builder;

public function getAbilitiesWithoutTarget(
    string|array|null $abilities = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ANY,
    array $context = [],
): array;
```

`getAbilitiesWithoutTarget()` defaults to `ANY`, the one exception to the `ALL`
default.

### The compiled gate

```php
public function compileGate(
    Builder $query,
    string|array $abilities,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
    ?Model $targetModel = null,
): CompilationResult;
```

```php
$gate = $guard->compileGate($query, 'view');

$gate->decision();          // Decision
$gate->grants();            // true only for Decision::True
$gate->isConstant();        // false only for Decision::NeedsQuery
$gate->toQuery();           // the predicate as SQL
$gate->spliceInto($query);  // attach to a host query, returning the host
```

`Warrant\DSL\Compiling\Decision`:

| Case | Meaning |
| --- | --- |
| `True` | granted without consulting a row |
| `False` | denied |
| `Unknown` | the compile reached a question it could not answer |
| `NeedsQuery` | not settled here; ask it in SQL |

`filterQuery()` spells a constant out as `1 = 1`, `1 = 0`, or `null`, because a row
filter has to say something. The boolean checks read the constant and return
without querying.

An empty ability set folds to `true`.

### Reachability

The schema is bound, so no schema argument:

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

public function reachabilityMap(?array $abilities = null): array;
public function reachabilitySatisfies(string|array $abilities, callable $passes, AbilityMatchMode $matchMode): bool;
public function abilitiesWhereReachability(callable $passes): array;
```

## `AuthorizesWithWarrant`

```php
use Warrant\AuthorizesWithWarrant;

class User extends Authenticatable
{
    use AuthorizesWithWarrant;
}

public function warrant(): WarrantGuard;   // === Warrant::guard($this)
```
