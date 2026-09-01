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
    public const model = Document::class; // the Eloquent model this governs
}
```

## The model constant

`const model` binds the schema to an Eloquent model class, and the model names the
schema back through the [`HasWarrantSchema`](/guides/checking-access/) trait. Warrant checks
that the two agree the first time it resolves the schema, and throws if they don't.

A schema and its model must name **each other**, so one model has exactly one
schema. A base schema may still be extended, but each concrete schema needs its own
model.

## The schema key

The **schema key** identifies the resource in rules, lookups, and middleware. It is
not declared on the schema: it is the key the schema is registered under in
`config/warrant.php`, which is its single source of truth. Read it back with:

```php
public static function schemaKey(): string; // 'documents'
```

Because the key lives in the config index, that method is a lookup and needs a
booted application.

:::tip[Why the key isn't derived]
Warrant used to derive the key from the model's table, which meant building the
schema index instantiated every registered model — loading and *booting* hundreds
of Eloquent models on the first check of every request. Declaring the key in config
makes registration a plain array of strings, so nothing is loaded until a schema is
actually used.
:::

## Abilities

Abilities are the verbs a rule can grant or deny. Declare each as a class
constant marked `#[Ability]`. The constant's **value** is the ability name used
in rules; the constant's *name* is irrelevant to Warrant (discovery is by
attribute, not by naming).

```php
use Warrant\Schema\Ability;

#[Ability] public const VIEW    = 'view';
#[Ability] public const APPROVE = 'approve';
```

```php
DocumentSchema::abilityNames(); // ['view', 'approve', ...]
```

:::note[Declaration order is preserved]
`abilityNames()` returns abilities in **declaration order**, not sorted. This
order is also the order they appear in the per-row `abilities` JSON column from
[`selectUserAbilities`](/guides/checking-access/#per-row-abilities). Condition keys, by
contrast, come back sorted.
:::

A rule that names an ability the schema doesn't declare is rejected — see
[Errors & exceptions](/reference/errors/) for the two distinct "unknown ability"
messages (one at rule-set validation, one at check time).

### Standard abilities

Warrant ships `Warrant\Schema\StandardAbilities` with common names if you want a shared
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
`#[RowCondition]` or `#[GlobalCondition]`. They're covered in depth in
[Conditions](/guides/conditions/).

## Context keys

For values known only at check time (the current tenant, an as-of date), a rule
references `@context <key>` and conditions read `$c->context` — no declaration
needed. To *require* a key, mark it `#[RequiredContext]` (schema-wide) or
`#[Ability(requiredContext: [...])]` (per ability). See
[Check-time context](/guides/context/).

## Schemas with no model

A schema may govern a "section" with no model at all — for gating things like
`settings` that only ever answer no-target checks:

```php
class SettingsSchema extends WarrantSchema
{
    public const model = ''; // no model

    #[Ability] public const MANAGE = 'manage';

    // Only global conditions make sense here — row conditions
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
| `public function implicitRules(): array\|WarrantRuleSet` | Rules always merged into every rule set — an admin escape hatch, a suspension lockout. See [Resolvers](/guides/resolvers/#implicit-rules). |
| `protected function defaultContext(): array` | Default check-time context, merged *under* explicit values. See [Check-time context](/guides/context/). |

## Registering the schema

Every schema must be listed in `config/warrant.php`, keyed by its schema key.
Unlisted schemas are unknown to checks, lookups, and middleware:

```php
'schemas' => [
    'documents' => App\Warrant\DocumentSchema::class,
    'settings'  => App\Warrant\SettingsSchema::class,
],
```

The array key *is* the schema key — the identifier that appears in your rule
strings and in the `RuleResolutionContext` handed to your resolver. Treat it like a
database identifier: renaming one changes the meaning of every stored rule that
references it.

Listing a schema does not load it. The index is a plain string-to-string map, so
registering hundreds of schemas costs one array; a schema class and its model are
loaded the first time that schema is used.

:::caution[One key per schema]
Registering the same schema under two keys throws when the index is built — a
schema needs a single key to write back into rule syntax. Deferred checks (the
value really is a `WarrantSchema`, and its model names it back) throw the first
time that schema is resolved, not at boot.
:::
