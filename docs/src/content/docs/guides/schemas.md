---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Schemas
description: Define the vocabulary of a resource — its abilities, its model, and its schema key.
sidebar:
  order: 1
---

A schema is a `Warrant\Schema\WarrantSchema` subclass, one per resource. It
declares the **abilities** that exist and the **conditions** a rule may test, and
binds them to a model. A schema is *vocabulary*, not policy — it decides nothing.

```php
use Warrant\Schema\WarrantSchema;

class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;    // the Eloquent model this governs
    // public const schemaKey = 'documents'; // optional override
}
```

## The model constant

`const model` binds the schema to an Eloquent model class. Warrant uses it to
resolve a schema from a model (and vice versa) and to derive the schema key.

## The schema key

The **schema key** identifies the resource in rules, lookups, and middleware. By
default it's derived from the model's table name:

```php
public static function schemaKey(): string; // 'documents'
```

Override it with the `schemaKey` constant when you want a stable key independent
of the table name.

:::caution[Deriving the key instantiates the model]
When `schemaKey` isn't set, Warrant computes the key as `(new model)->getTable()`.
That means a **schema with no model** (`const model = ''`) *must* set
the `schemaKey` constant — otherwise Warrant tries to instantiate `''` and fatals.
:::

## Abilities

Abilities are the verbs a rule can grant or deny. Declare each as a class
constant marked `#[Ability]`. The constant's **value** is the ability name used
in rules; the constant's *name* is irrelevant to Warrant (discovery is by
attribute, not by naming).

```php
use Warrant\Ability;

#[Ability] public const VIEW    = 'view';
#[Ability] public const APPROVE = 'approve';
```

```php
DocumentSchema::declaredAbilities(); // ['view', 'approve', ...]
```

:::note[Declaration order is preserved]
`declaredAbilities()` returns abilities in **declaration order**, not sorted. This
order is also the order they appear in the per-row `abilities` JSON column from
[`selectUserAbilities`](/guides/checking-access/#per-row-abilities). Condition keys, by
contrast, come back sorted.
:::

A rule that names an ability the schema doesn't declare is rejected — see
[Errors & exceptions](/reference/errors/) for the two distinct "unknown ability"
messages (one at rule-set validation, one at check time).

### Standard abilities

Warrant ships `Warrant\StandardAbilities` with common names if you want a shared
vocabulary:

```php
StandardAbilities::VIEW;     // 'view'
StandardAbilities::CREATE;   // 'create'
StandardAbilities::UPDATE;   // 'update'
StandardAbilities::DELETE;   // 'delete'
StandardAbilities::ARCHIVE;  // 'archive'
```

## Conditions

Conditions are the predicates a rule may test. Each is a public method marked
`#[TargetedCondition]` or `#[GlobalCondition]`. They're covered in depth in
[Conditions](/guides/conditions/).

## Context keys

For values known only at check time (the current tenant, an as-of date), a schema
declares `#[ContextKey]` constants. See [Check-time context](/guides/context/).

## Schemas with no model

A schema may govern a "section" with no model at all — for gating things like
`settings` that only ever answer no-target checks:

```php
class SettingsSchema extends WarrantSchema
{
    public const model = '';              // no model
    public const schemaKey = 'settings';  // REQUIRED when there's no model

    #[Ability] public const MANAGE = 'manage';

    // Only global conditions make sense here — targeted conditions
    // are treated as false in a no-target check.
    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }
}
```

Targeted checks against a model-less schema throw; use
[no-target checks](/guides/checking-access/#no-target-checks) instead.

## Overridable hooks

| Hook | Purpose |
|---|---|
| `protected function implicitRules(): array` | Rules always merged into every rule set — an admin escape hatch, a suspension lockout. See [Resolvers](/guides/resolvers/#implicit-rules). |
| `protected function defaultContext(): array` | Default check-time context, merged *under* explicit values. See [Check-time context](/guides/context/). |

## Registering the schema

Every schema must be listed in `config/warrant.php`. Unlisted schemas are unknown
to lookups and middleware:

```php
'schemas' => [
    App\Warrant\DocumentSchema::class,
    App\Warrant\SettingsSchema::class,
],
```

:::caution[Duplicate keys throw]
Two schemas that resolve to the same schema key — or claim the same model — throw
(`Duplicate schema for schema key ...`) when the schema registry is first
resolved from the container. Keys must be unique across the registry.
:::
