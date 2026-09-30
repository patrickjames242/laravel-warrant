---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Configuration
description: Every key in config/warrant.php.
sidebar:
  order: 10
---

```bash
php artisan vendor:publish --tag=warrant-config
```

```php
// config/warrant.php

return [
    // The class that hands Warrant the rules for the current request.
    // Warrant ships NO default; you must set this.
    'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,

    // Every schema Warrant should know about, keyed by its schema key.
    'schemas' => [
        'documents' => App\Warrant\DocumentSchema::class,
        'folders'   => App\Warrant\FolderSchema::class,
        'settings'  => App\Warrant\SettingsSchema::class,
    ],

    // Resolve Warrant abilities through Laravel's Gate: $user->can(), @can,
    // can: middleware. Set false to opt out. Defaults to true.
    'register_gate' => true,
];
```

## `rule_resolver`

A class-string implementing `Warrant\Rules\RuleResolver`. Built through the
container, so constructor dependencies are injected.

No default ships. Until it is set, checks cannot run:

```text
No Warrant rule resolver configured. Set warrant.rule_resolver to a class
implementing Warrant\Rules\RuleResolver.
```

See [the resolver contract](/supplying-rules/resolver/).

## `schemas`

A map of schema key to schema class. The array key **is** the schema key: what rule
strings write, and what your resolver is handed.

Listing a schema does not load it. The index is a plain string-to-string map, so
registering hundreds costs one array, and a schema class and its model are loaded
the first time that schema is used.

Registering the same schema under two keys throws when the index is built, since a
schema needs one key to write back into rule syntax.

Treat a key like a database identifier. Renaming one changes the meaning of every
stored rule that references it.

See [registering schemas](/schemas/registering/).

## `register_gate`

When true, the default, Warrant registers a `Gate::before` hook so its abilities
resolve through `$user->can()`, `@can`, `Gate::authorize()`, and `can:` route
middleware.

The hook returns `null` for abilities no registered schema declares, so Laravel
falls through to your own policies. Guests always fall through.

Set it false to reach Warrant only through the facade, the guards, the query
scopes, and the `warrant` middleware. See
[the Gate bridge](/checking/gate/).

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| Laravel | 11, 12, or 13 |
| Database | PostgreSQL, MySQL or MariaDB, or SQLite |

Laravel 13 requires PHP 8.3 or newer, so on PHP 8.2 Composer resolves Warrant
against Laravel 11 or 12.

The database list is about the [per-row ability column](/checking/abilities/),
which needs a native JSON aggregate. Everything else is ordinary SQL.

## Registered middleware aliases

Registered by the service provider, with nothing to add:

```text
warrant
warrant.could-ever      warrant.could-ever.any
warrant.always          warrant.always.any
warrant.never           warrant.never.any
```

## Event listeners

Also registered by the service provider. They drop the per-request memo wherever a
process moves on to unrelated work:

| Runtime | Events |
| --- | --- |
| Octane | `RequestTerminated`, `TaskTerminated` |
| Queue workers | `JobProcessed`, `JobFailed` |

See [Octane and long-lived processes](/production/octane/).
