---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Reachability
description: Ask "could this user ever?" structurally — no conditions, no SQL — to drive navigation and gate whole sections.
sidebar:
  order: 8
---

Every check so far asks about a concrete row (or a global [no-target
check](/guides/checking-access/#no-target-checks)). A different,
cheaper question is *"could this user **ever** update a document — is it even
worth showing the button, or building the section?"*

That's **reachability**: a purely **structural** look at the rules the resolver
hands this user. It evaluates **no conditions** and runs **no SQL** — it only asks
whether a grant is *conceivable*.

## The three states

The rule of thumb is *unconditionality*. A rule with an `if` is a "maybe" — whether
it fires depends on a condition we don't evaluate here; only unconditional rules
make us certain. Each ability lands in one of three states:

| `Warrant\Reachability` | Meaning | Typical UI use |
|---|---|---|
| `NEVER` | No rule grants it, or an unconditional `cannot` forbids it. | Hide the control entirely. |
| `MAYBE` | A condition decides — they might or might not. | Show it, but check per row. |
| `ALWAYS` | Unconditionally granted, with no unconditional deny. | Show it, enabled. |

The decision table, resolved top to bottom for one ability:

1. an unconditional `cannot` → `NEVER` (an undodgeable deny wins);
2. no `can` rule lists it → `NEVER` (no grant path at all);
3. an unconditional `can` and no *conditional* `cannot` → `ALWAYS`;
4. otherwise → `MAYBE`.

A *conditional* `cannot` is intentionally ignored: a different row or state can
dodge it, so it never lowers certainty. This mirrors the compiler's own hard edges
(see [How it compiles to SQL](/guides/how-it-compiles/)).

:::caution[`ALWAYS` is a shape guarantee, not a per-row one]
Because `ALWAYS` ignores conditional denies, it means "granted by the rules'
shape" — **not** a promise that every row passes. The per-row check is still the
source of truth; reachability just tells you whether it's worth asking.
:::

## Asking the question

```php
use Warrant\Reachability;

// One ability, three-valued:
Document::abilityReachability('update');            // Reachability::NEVER | MAYBE | ALWAYS

// The boolean questions:
Document::userCouldEverHave('update');              // reachability !== NEVER
Document::userAlwaysHas('view');                    // reachability === ALWAYS
Document::userNeverHas('delete');                   // reachability === NEVER

// Whole-schema lists (over every declared ability):
Document::getUserPossibleAbilities();               // ['view', 'update', 'approve']
Document::getUserGuaranteedAbilities();             // ['view']
Document::getUserImpossibleAbilities();             // ['delete']
```

Every method takes an optional `$user` (defaults to `auth()->user()`), and the
boolean forms take an [`AbilityMatchMode`](/guides/checking-access/#match-modes) —
`ALL` (default) needs every listed ability to qualify, `ANY` needs one.

:::note[No `context:`, but a user is still required]
There is **no** `context:` argument: [`@context`](/guides/context/) only ever feeds
condition evaluation, which reachability never does. The user *is* still needed,
because the resolver may hand a different rule set to each user, role, or tenant.
:::

The same helpers live on the schema and the `Warrant` facade too:

```php
DocumentSchema::userCouldEverHave('update', $user);
Warrant::userCouldEverHave('documents', 'update', $user);   // by schema key or class
```

## Rendering UI without a query per link

Reachability is built for exactly this — deciding what to render before you ever
touch a row:

```php
use Warrant\Reachability;

match (Document::abilityReachability('update')) {
    Reachability::NEVER  => /* omit the Edit link entirely */,
    Reachability::ALWAYS => /* show it, enabled */,
    Reachability::MAYBE  => /* show it; the per-row check decides per document */,
};
```

## Gating routes by reachability

The same questions have matching [route middleware](/guides/middleware/) guards —
gate a section by whether the user *could ever* act, or short-circuit a route to
those who provably never can:

```php
use Warrant\WarrantMiddleware;

// Only reachable if the user could ever view a document — otherwise 403:
Route::get('/documents', ...)->middleware(WarrantMiddleware::couldEver('documents', 'view'));

// Only when the ability is guaranteed:
WarrantMiddleware::always('documents', 'create', fn () => Route::post('/documents', ...));

// Only when the user provably never can (e.g. an upsell page):
Route::get('/upgrade', ...)->middleware(WarrantMiddleware::never('documents', 'approve'));
```

These guards are **target-free**: the first argument is always a schema key (or a
schema/model class), never a route-bound parameter — reachability has no row to
bind. See [Route middleware](/guides/middleware/#reachability-guards) for the alias
grammar and [the Middleware API](/reference/middleware-api/) for signatures.
