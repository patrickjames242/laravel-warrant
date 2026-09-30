---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Warrant facade
description: Every method on the facade, which is the WarrantManager.
sidebar:
  order: 1
---

`Warrant\Facades\Warrant`, backed by `Warrant\WarrantManager`. Schema-less: the
target names the schema and, optionally, the row.

## Guards

```php
Warrant::guard(?Authenticatable $user = null): WarrantGuard;
Warrant::forSchema(Model|WarrantSchema|string $schema, ?Authenticatable $user = null): WarrantGuardForSchema;
Warrant::registry(): SchemaRegistry;
```

## Checks

```php
Warrant::can(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool;
Warrant::canAny(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool;
Warrant::cannot(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool;
Warrant::authorize(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): void;
Warrant::authorizeAny(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): void;
Warrant::abilities(Model|string|array $target, array $context = [], ?Authenticatable $user = null): array;
```

There is no `matchMode:` argument. ALL is `can`, ANY is `canAny`, and likewise
`authorize` and `authorizeAny`.

`authorize` and `authorizeAny` return `void` and throw
[`WarrantAuthorizationException`](/reference/exceptions/), rendered as a 403,
carrying a diagnosed denial context.

## Target forms

```php
Warrant::can('update', $document);                  // model instance, a row
Warrant::can('update', [Document::class, $id]);     // [class, id], a row by key
Warrant::can('create', Document::class);            // model class, no row
Warrant::can('create', DocumentSchema::class);      // schema class, no row
Warrant::can('create', 'documents');                // schema key, no row
```

A bare scalar names the schema, never a row.

## Reachability

Schema first, no match mode, no context. A user is still required.

```php
Warrant::reachabilityOf(Model|WarrantSchema|string $schema, string $ability, ?Authenticatable $user = null): Reachability;

Warrant::couldEverHave($schema, string|array $abilities, ?Authenticatable $user = null): bool;
Warrant::couldEverHaveAny($schema, string|array $abilities, ?Authenticatable $user = null): bool;
Warrant::alwaysHas($schema, string|array $abilities, ?Authenticatable $user = null): bool;
Warrant::alwaysHasAny($schema, string|array $abilities, ?Authenticatable $user = null): bool;
Warrant::neverHas($schema, string|array $abilities, ?Authenticatable $user = null): bool;
Warrant::neverHasAny($schema, string|array $abilities, ?Authenticatable $user = null): bool;

Warrant::possibleAbilities($schema, ?Authenticatable $user = null): array;
Warrant::guaranteedAbilities($schema, ?Authenticatable $user = null): array;
Warrant::impossibleAbilities($schema, ?Authenticatable $user = null): array;
```

## Authoring

```php
Warrant::condition(?string $syntax = null, array $bindings = []): IBooleanExpressionNode|WarrantConditionBuilder;
Warrant::rule(?string $syntax = null, Model|WarrantSchema|string|null $schema = null, array $bindings = []): WarrantRule|WarrantRuleBuilder;
Warrant::ruleSet(string $syntax, Model|WarrantSchema|string|null $schema = null, array $bindings = []): WarrantRuleSet;
Warrant::group(string $syntax, array $bindings = []): RuleSetGroup;
Warrant::ruleTemplate(string $syntax, array $bindings = []): WarrantRuleTemplate;
```

Given syntax, each returns the finished construct. Given nothing, `condition()` and
`rule()` return the builder that composes one. A rule set and a group are
collections, so their syntax is required.

```php
Warrant::condition('is_owner or is_admin');                  // IBooleanExpressionNode
Warrant::condition()->if('is_owner')->orIf('is_admin');      // WarrantConditionBuilder
Warrant::rule('for documents if is_mine they can view');     // WarrantRule
Warrant::ruleSet('for documents { they can view }');         // WarrantRuleSet
Warrant::group('for documents { … } for folders { … }');     // RuleSetGroup
```

A `for <schema>` header is accepted on a condition expression and discarded, so a
condition written as a string is as checkable as every other construct.

## Flushing

```php
Warrant::flush(?Authenticatable $user = null): void;
```

Its `$user` does **not** default to the current user. Omitting it flushes everyone.

## The registry

```php
Warrant::registry()->registeredSchemas(): array;

Warrant::registry()->resolveSchemaClassOrNull(Model|WarrantSchema|string|null $ref): ?string;
Warrant::registry()->resolveSchemaClassOrFail($ref, bool $passThroughNull = false): ?string;
Warrant::registry()->resolveModelOrNull($ref): ?string;
Warrant::registry()->resolveModelOrFail($ref, bool $passThroughNull = false): ?string;
Warrant::registry()->resolveSchemaKeyOrNull($ref): ?string;
Warrant::registry()->resolveSchemaKeyOrFail($ref, bool $passThroughNull = false): ?string;
```

A schema resolves to itself but must be registered. A model resolves through its own
`warrantSchema()`. A bare string is a literal schema key, returned unchanged by the
`resolveSchemaKey*` pair; it is `resolveSchemaClass*` that rejects an unregistered
key.
