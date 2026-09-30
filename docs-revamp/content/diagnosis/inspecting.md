---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Inspecting what is loaded
description: Dumping the rules, the registry, and the predicate for one user, right now.
sidebar:
  order: 5
---

Four things are worth dumping, and between them they answer almost every question.

## 1. The rules in effect

```php
Warrant::forSchema(Document::class, $user)->resolvedRuleSet()->toSyntax();
```

```warrant
if is_suspended they cannot *
if is_super_admin they can *
if is_mine or in_my_team they can view, update
if is_locked they cannot update because 'This document is locked.'
```

That is what your resolver returned plus the schema's
[implicit rules](/schemas/schema-policy/), merged and validated. Start here.

The first two lines above are the ones people are surprised by, because they were
never in the resolver.

If `toSyntax()` throws, a rule holds a value with no inline form. Use the bound
form:

```php
$bound = $set->toBoundSyntax();
$bound->syntax;
$bound->bindings;
```

## 2. The reachability picture

```php
Warrant::possibleAbilities(Document::class, $user);
Warrant::guaranteedAbilities(Document::class, $user);
Warrant::impossibleAbilities(Document::class, $user);
```

No SQL runs. `impossibleAbilities` containing the ability in question ends the
investigation: no row could have helped.

## 3. The predicate

```php
$guard = Warrant::forSchema(Document::class, $user);

$guard->filterQuery($guard->query(), 'view')->toRawSql();
```

Or, when you want to know whether SQL was needed at all:

```php
$gate = $guard->compileGate($guard->query(), 'view');

$gate->decision();     // True | False | Unknown | NeedsQuery
$gate->grants();       // true only for True
$gate->isConstant();   // false only for NeedsQuery
```

`Decision::Unknown` is the interesting one. It means the compile reached a question
it could not answer, which is usually a missing context key or a row condition
asked with no row.

## 4. The registry

```php
Warrant::registry()->registeredSchemas();

Warrant::registry()->resolveSchemaClassOrNull('documnets');   // null on a typo
Warrant::registry()->resolveSchemaKeyOrFail(Document::class);
Warrant::registry()->resolveModelOrFail('documents');
```

And what a schema's vocabulary is:

```php
DocumentSchema::abilityNames();
DocumentSchema::rowConditionKeys();
DocumentSchema::globalConditionKeys();
DocumentSchema::requiredContextKeys();
DocumentSchema::hasRows();
DocumentSchema::hasRowKey();
```

The row and global split answers "why does this grant nothing in a no-row check".

## A command worth having

```php
class WarrantExplain extends Command
{
    protected $signature = 'warrant:explain {user} {schema} {ability?}';

    public function handle(): void
    {
        $user   = User::findOrFail($this->argument('user'));
        $class  = Warrant::registry()->resolveSchemaClassOrFail($this->argument('schema'));
        $guard  = Warrant::forSchema($class, $user);

        $this->info('Rules in effect');
        $this->line($guard->resolvedRuleSet()->toSyntax());

        $this->newLine();
        $this->info('Reachability');
        $this->table(['Ability', 'State'], collect($class::abilityNames())
            ->map(fn ($a) => [$a, $guard->reachabilityOf($a)->name]));

        if ($ability = $this->argument('ability')) {
            $this->newLine();
            $this->info("Predicate for {$ability}");
            $this->line($guard->filterQuery($guard->query(), $ability)->toRawSql());
        }
    }
}
```

```bash
php artisan warrant:explain 42 documents view
```

## Watching the queries

```php
DB::listen(fn ($q) => logger('sql', ['sql' => $q->sql, 'bindings' => $q->bindings]));
```

The three constants to look for are `1 = 1`, `1 = 0`, and `null`. See
[where unknown goes in SQL](/sql/unknown/).

## In a test

```php
it('shows what is in effect', function () {
    dump(Warrant::forSchema(Document::class, $this->user)->resolvedRuleSet()->toSyntax());
    dump(Warrant::possibleAbilities(Document::class, $this->user));
    dump(Document::query()->userHasAbility('view', $this->user)->toRawSql());
});
```

Three dumps, and the failing test usually explains itself.

## A note on exposing this

A rule set describes what someone may do and often names the shape of your data.
Gate a debug endpoint on a real ability rather than on an environment check:

```php
Warrant::authorize('debug', 'settings');
```
