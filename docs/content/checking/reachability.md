---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Checking reachability
description: Could they ever? The API, the route guards, and what to render from each answer.
sidebar:
  order: 6
---

Reachability asks whether a grant is conceivable, by reading the rules rather than
the rows. No row or global condition runs, no SQL runs, and there is no `context:`
argument. Derived conditions and `can(...)` references are followed. The concepts are in [reachability](/concepts/reachability/); this is the
surface.

## One ability

```php
use Warrant\Reachability;

Warrant::reachabilityOf(Document::class, 'update');   // NEVER | MAYBE | ALWAYS
```

## The boolean questions

```php
Warrant::couldEverHave(Document::class, 'update');   // !== NEVER
Warrant::alwaysHas(Document::class, 'view');         // === ALWAYS
Warrant::neverHas(Document::class, 'delete');        // === NEVER
```

Each takes a list, requiring every ability to qualify, and each has an `*Any`
variant requiring one:

```php
Warrant::couldEverHave(Document::class, ['view', 'update']);
Warrant::couldEverHaveAny(Document::class, ['view', 'approve']);
Warrant::alwaysHasAny(Document::class, ['view', 'approve']);
Warrant::neverHasAny(Document::class, ['delete', 'purge']);
```

## Whole-schema lists

```php
Warrant::possibleAbilities(Document::class);      // ['view', 'update', 'approve']
Warrant::guaranteedAbilities(Document::class);    // ['view']
Warrant::impossibleAbilities(Document::class);    // ['delete']
```

## Through the guards

```php
DocumentSchema::guard($user)->couldEverHave('update');      // schema bound
$user->warrant()->couldEverHave(Document::class, 'update'); // user bound
```

On the schema-bound guard there is no schema argument, and the building blocks the
rest are expressed in terms of are available too:

```php
$guard->reachabilityMap(['view', 'update']);
$guard->reachabilitySatisfies(['view'], fn ($r) => $r !== Reachability::NEVER, AbilityMatchMode::ALL);
$guard->abilitiesWhereReachability(fn ($r) => $r === Reachability::ALWAYS);
```

A user is still required, since your provider may hand a different rule set to each
user, role, or tenant.

## Rendering from it

```php
use Warrant\Reachability;

match (Warrant::reachabilityOf(Document::class, 'update')) {
    Reachability::NEVER  => null,                 // omit the Edit column entirely
    Reachability::ALWAYS => $this->editColumn(),  // render it, enabled
    Reachability::MAYBE  => $this->editColumn(),  // render it; per row decides
};
```

Building a nav in one pass:

```php
$nav = collect([
    'Documents' => Document::class,
    'Timesheets' => Timesheet::class,
    'Settings'  => 'settings',
])->filter(fn ($schema) => Warrant::couldEverHave($schema, 'view'));
```

That is the case the feature exists for. Doing it with per-row checks would mean a
query per link, on every page, to answer a question no row could change.

## Route guards

```php
use Warrant\Middleware\WarrantMiddleware;

// 403 unless the user could ever view a document:
Route::get('/documents', ...)
    ->middleware(WarrantMiddleware::couldEver('documents', 'view'));

// Only when the rules guarantee the ability:
WarrantMiddleware::always('documents', 'create', fn () => Route::post('/documents', ...));

// Only when the user provably never can, for an upsell page:
Route::get('/upgrade', ...)
    ->middleware(WarrantMiddleware::never('documents', 'approve'));
```

These are target-free. The first argument is always a schema key or a schema or
model class, never a route parameter, because reachability has no row to bind.
Passing a route parameter throws:

```text
Unable to resolve Warrant schema for [document]; reachability guards take a schema key.
```

The mode and match mode live in the middleware alias rather than the parameters, so
everything after the colon is schema key and abilities, and an ability may safely be
named `any` or `all`:

```text
warrant.could-ever:documents,view       warrant.could-ever.any:documents,view,approve
warrant.always:documents,view           warrant.always.any:documents,view,approve
warrant.never:documents,view            warrant.never.any:documents,view,approve
```

All of them are built on `AbstractReachabilityMiddleware`, which fixes two things
per subclass: which outcomes pass, and how several abilities combine.

## What it will not tell you

`MAYBE` says a condition decides, not which way. And a row-bound `can(...)` or
`check(...)` can never make an ability `ALWAYS`, because the row it names may not
exist. Use reachability to decide whether to render a control, and the per-row
check to decide whether the action succeeds.
