---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: HasWarrantSchema
description: The model trait — query scopes, the abilities helper, and warrantQualifyColumn.
sidebar:
  order: 5
---

`Warrant\HasWarrantSchema`, on the model a schema governs.

```php
use Warrant\HasWarrantSchema;

class Document extends Model
{
    use HasWarrantSchema;

    public static function warrantSchema(): string
    {
        return \App\Warrant\DocumentSchema::class;
    }
}
```

The trait declares `warrantSchema(): string` abstract **and static**. The returned
schema's `const model` must equal this model's class, or Warrant throws when a
scope resolves the schema:

```text
Model [...] names schema [...], but that schema names model [...]; a schema and its
model must name each other.
```

Because `warrantSchema()` is inherited, a model subclass fails this check unless it
declares its own schema.

## Query scopes

These **do** take an `AbilityMatchMode`.

```php
public function scopeUserHasAbility(
    EloquentBuilder $query,
    string|array $abilities,
    ?Authenticatable $user = null,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    array $context = [],
): EloquentBuilder;

public function scopeSelectUserAbilities(
    EloquentBuilder $query,
    ?Authenticatable $user = null,
    string $selectedAbilitiesKey = 'abilities',
    ?array $onlyAbilities = null,
    array $context = [],
): EloquentBuilder;
```

```php
Document::query()->userHasAbility('view')->paginate();
Document::query()->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ANY)->get();
Document::query()->selectUserAbilities(onlyAbilities: ['update'])->get();
```

`userHasAbility([])` leaves the query unchanged.

`selectUserAbilities` targets rows via `getQualifiedKeyName()`. Its JSON column is
in ability declaration order and needs a
[supported driver](/sql/databases/).

## Instance method

```php
public function loadUserAbilities(
    ?Authenticatable $user = null,
    string $selectedAbilitiesKey = 'abilities',
    array $context = [],
): array;   // computes, then setAttribute() on the instance
```

## `warrantQualifyColumn`

```php
public function warrantQualifyColumn(?string $column = null): string;
```

Qualifies a column with the name this model's rows answer to. Ordinarily the table,
exactly as `qualifyColumn()` would. Inside a Warrant row condition it is whatever
the compiler named the row: a host query's alias, or a cross-schema hop's.

```php
public function scopeOwnedBy($query, string $userId)
{
    return $query->where($this->warrantQualifyColumn('owner_id'), $userId);
}
```

Also reachable from the builder, so a scope may use whichever it has to hand:

```php
return $query->where($query->warrantQualifyColumn('owner_id'), $userId);
```

Omit the column for the model's key. A column already carrying a table prefix is
returned untouched, matching `qualifyColumn()`.

A scope is free to keep using `qualifyColumn()`. It then names the table always,
and so cannot be reached through an alias. Warrant does not override
`qualifyColumn()` to do this, because a trait method beats an inherited one and a
base model's own override would be silently replaced.

See [frames](/concepts/frames/).

## The user argument

`$user` defaults to `auth()->user()`. The scopes and `loadUserAbilities()` throw a
`LogicException` when none is available.

## `SelectUserAbilitiesScope`

```php
Warrant\SelectUserAbilitiesScope implements Illuminate\Database\Eloquent\Scope;
```

Its `apply()` no-ops when there is no authenticated user or the model lacks a
`warrantSchema()` method; otherwise it calls `selectUserAbilities($currentUser)`.

The trait does **not** register it. Attach it yourself if you want the column on
every query:

```php
protected static function booted(): void
{
    static::addGlobalScope(new \Warrant\SelectUserAbilitiesScope);
}
```
