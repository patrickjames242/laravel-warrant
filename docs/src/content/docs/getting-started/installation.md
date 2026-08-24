---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Installation
description: Install Laravel Warrant via Composer, publish the config, and meet the requirements.
sidebar:
  order: 1
---

## Requirements

| | |
|---|---|
| **PHP** | 8.2 or newer |
| **Laravel** | 11 or 12 |
| **Database** | PostgreSQL, MySQL / MariaDB, or SQLite |

Warrant compiles rules into SQL for those three driver families. Any database
your app already runs on Laravel 11/12 with one of those drivers will work.

## Install

```bash
composer require patrickhanna/laravel-warrant
```

The service provider is registered automatically through Laravel's package
discovery — there is nothing to add to `config/app.php`.

## Publish the config

Warrant reads its settings from `config/warrant.php`: which resolver supplies your
rules, which schemas exist, and whether to hook into Laravel's Gate. Publish the
file so you can edit it in place:

```bash
php artisan vendor:publish --tag=warrant-config
```

This writes `config/warrant.php`:

```php
return [
    // The class that hands Warrant the rules for the current request.
    // Warrant ships NO default — you must set this.
    'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,

    // Every schema Warrant should know about.
    'schemas' => [
        App\Warrant\DocumentSchema::class,
    ],

    // Resolve Warrant abilities through Laravel's Gate ($user->can(), @can,
    // can: middleware). Set false to opt out. Defaults to true.
    'register_gate' => true,
];
```

:::caution
There is no built-in resolver. Until you set `rule_resolver` to a class that
implements `Warrant\RuleResolver`, checks cannot run. Writing that class is the
[Quick start](/getting-started/quick-start/).
:::

## What you'll build

Warrant has four moving parts. Installing the package only gives you the engine;
the four pieces below are yours to write:

1. A **schema** per resource — the vocabulary of abilities and conditions.
2. **Rules** in Warrant's rule language — the actual policy, stored as data.
3. A **resolver** — the glue that fetches this user's rules at request time.
4. The **checks** in your app — query scopes, model helpers, middleware, and
   Laravel's native Gate (`$user->can()`, `@can`, `can:` routes).

The [Quick start](/getting-started/quick-start/) walks through the smallest
version of all four.
