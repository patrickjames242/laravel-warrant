---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Registering schemas
description: The config index, what it costs, and how to inspect it.
sidebar:
  order: 7
---

Every schema is listed in `config/warrant.php`, keyed by its schema key. Unlisted
schemas are unknown to checks, lookups, and middleware.

```php
'schemas' => [
    'documents'   => App\Warrant\DocumentSchema::class,
    'folders'     => App\Warrant\FolderSchema::class,
    'timesheets'  => App\Warrant\TimesheetSchema::class,
    'pay_periods' => App\Warrant\PayPeriodSchema::class,
    'settings'    => App\Warrant\SettingsSchema::class,
],
```

The array key **is** the schema key: the identifier your rule strings write, and
the one handed to your provider in the `RuleProviderContext`. Treat it like a
database identifier. Renaming one changes the meaning of every stored rule that
references it.

## Listing a schema does not load it

The index is a plain string-to-string map, so registering hundreds of schemas costs
one array. A schema class and its model are loaded the first time that schema is
actually used.

That is also why the consistency checks are deferred rather than run at boot. Each
one requires loading a class, which is exactly what the index avoids. They fire the
first time a schema is resolved:

```text
Schema key [documents] is registered to [App\Foo], which is not a Warrant\Schema\WarrantSchema.
Schema [X] names model [Y], which is not an Eloquent model.
Schema [X] names model [Y], but that model does not use the Warrant\HasWarrantSchema trait.
Model [Y] must declare warrantSchema() as `public static`.
Schema [X] names model [Y], but that model names schema [Z]; a schema and its model must name each other.
```

:::caution[One key per schema]
Registering the same schema under two keys throws when the index is built. A schema
needs a single key to write back into rule syntax.
:::

## Inspecting the registry

The registry normalizes any accepted reference to a coordinate. Every provider
comes in an `OrNull` form and an `OrFail` form:

```php
Warrant::registry()->registeredSchemas();

Warrant::registry()->resolveSchemaClassOrNull($ref);
Warrant::registry()->resolveSchemaClassOrFail($ref, $passThroughNull = false);
Warrant::registry()->resolveModelOrNull($ref);
Warrant::registry()->resolveModelOrFail($ref);
Warrant::registry()->resolveSchemaKeyOrNull($ref);
Warrant::registry()->resolveSchemaKeyOrFail($ref);
```

A reference may be a model class or instance, a schema key, a schema class or
instance, or null:

```php
Warrant::registry()->resolveSchemaKeyOrFail(Document::class);     // 'documents'
Warrant::registry()->resolveSchemaKeyOrFail($document);           // 'documents'
Warrant::registry()->resolveSchemaClassOrFail('documents');       // App\Warrant\DocumentSchema
```

A schema resolves to itself but must be registered, since an unregistered schema
has no key and nothing could name it in rule syntax. A model resolves through its
own `warrantSchema()`. A bare string is treated as a literal schema key and is
returned unchanged by the `resolveSchemaKey*` pair, so rule syntax still parses and
writes without a registry. It is `resolveSchemaClass*` that rejects an unregistered
key:

```text
No Warrant schema registered for reference [documnets].
```

That is a useful line to have in a debugging session, because a typo'd key in a
stored rule surfaces as exactly that.

## Naming keys

Some habits that pay off later.

Use the plural table-ish form, since that is what reads best in rules:
`documents`, `pay_periods`, `shift_days`.

Keep them stable. A rename means a data migration over every stored rule. If you
have to, `WarrantSyntax::parse()` and `toSyntax()` give you a round-trip to rewrite
them with.

Use the same key in the rule text header as in config, and prefer putting the
header in the string:

```php
Warrant::parse('for documents { if is_mine they can view }')->ruleSet();
```

A header travels with the string, so editor tooling reading your source knows which
schema to check the names against. Leaving the header off and supplying the schema
from PHP with `scopedTo('documents')` leaves the string unchecked. It is still valid, just unverifiable from the outside.
