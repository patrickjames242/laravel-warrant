---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Reachability
description: Ask "could this user ever?" from the rules alone — no row, no SQL — to drive navigation and gate whole sections.
sidebar:
  order: 8
---

Every check so far asks about a concrete row (or a global [no-target
check](/guides/checking-access/#no-target-checks)). A different,
cheaper question is *"could this user **ever** update a document — is it even
worth showing the button, or building the section?"*

That's **reachability**: a look at the rules the provider hands this user,
asked without a row. It runs **no SQL** and never evaluates a row or global
condition. It does follow everything that can be answered without a row, so the
answer is as sharp as the rules allow.

## The three states

| `Warrant\Reachability` | Meaning | Typical UI use |
|---|---|---|
| `NEVER` | No rule can ever grant it: nothing grants it, an unconditional `cannot` forbids it, or every grant needs something that is never true. | Hide the control entirely. |
| `MAYBE` | A condition decides. They might or might not have it. | Show it, but check per row. |
| `ALWAYS` | Every way the rules can come out grants it. | Show it, enabled. |

## How an ability is judged

Reachability folds an ability the same way the [compiler](/guides/how-it-compiles/)
does: the `can` rules are ORed together, then ANDed with the negation of every
`cannot`. The difference is that it has no row, so for each part of a condition it
tracks every value that part could still take (true, false, or unknown).

- **A row or global condition** (`is_owner`, `is_admin`) could be anything. It
  is never evaluated here.
- **A [derived condition](/guides/conditions/#derived-conditions)** is read through to what it
  answers with. If it answers `true`, the condition is always true. If it answers
  `false`, it is never true. If it answers `null`, it is unknown, which never
  grants. If it answers with an expression, that expression is judged by these
  same rules.
- **`can(x)`, `can(x for other_schema)`:** whatever ability `x` comes out as. It
  is judged the same way, against the rules this user is given for that schema.
- **`check(... for other_schema)`:** whatever its predicate comes out as. A
  `can(...)` inside the predicate asks about an ability of the schema it checks.
- **A row-bound reference** (`can(x for folders(@column folder_id))`,
  `check(... for folders(@context id))`): as above, but the row may not exist. So
  it can rule a grant out, but never guarantee one.

So an ability that leans on another depends on how the two are combined:

```text
if can(manage for folders) or is_owner they can edit     # MAYBE, even if nothing grants `manage`
if can(manage for folders) and is_owner they can edit    # NEVER, if nothing grants `manage` to this user
if can(publish) they can view                            # ALWAYS, if `publish` is granted unconditionally
```

The same goes for denies. A `cannot` whose condition can never be true leaves an
unconditional grant `ALWAYS`. A `cannot` that hinges on an ability the user always
has is as good as unconditional, so the result is `NEVER`.

When the analysis is unsure it answers `MAYBE`, never a wrong `NEVER` or `ALWAYS`.
That covers an ability that refers back to itself (a cycle, which the compiler
rejects), a schema whose rules cannot be resolved, and a condition that appears
twice (`a or not a` is judged as if the two `a`s were independent).

:::caution[`ALWAYS` is a guarantee about the rules, not about a row]
`ALWAYS` means that no row, context or condition outcome can make the rules deny
the ability. The per-row check is still the source of truth; reachability just
tells you whether it's worth asking.
:::

## Asking the question

Reachability lives on the same [authorization
engine](/guides/checking-access/#the-authorization-engine) as every other check.
On the `Warrant` facade the first argument names the schema — a schema/model class
or a schema key:

```php
use Warrant\Reachability;

// One ability, three-valued:
Warrant::reachabilityOf(Document::class, 'update');   // Reachability::NEVER | MAYBE | ALWAYS

// The boolean questions:
Warrant::couldEverHave(Document::class, 'update');    // reachability !== NEVER
Warrant::alwaysHas(Document::class, 'view');          // reachability === ALWAYS
Warrant::neverHas(Document::class, 'delete');         // reachability === NEVER

// Whole-schema lists (over every declared ability):
Warrant::possibleAbilities(Document::class);          // ['view', 'update', 'approve']
Warrant::guaranteedAbilities(Document::class);        // ['view']
Warrant::impossibleAbilities(Document::class);        // ['delete']
```

Every method takes an optional `$user` (defaults to `auth()->user()`). To ask
about several abilities at once, the boolean forms have `*Any` variants —
`couldEverHave`/`alwaysHas`/`neverHas` require **every** listed ability to qualify,
while `couldEverHaveAny`/`alwaysHasAny`/`neverHasAny` require **any** one.

:::note[No `context:`, but a user is still required]
There is **no** `context:` argument: [`@context`](/guides/context/) only ever feeds
row and global conditions, which reachability never evaluates. The user *is* still needed,
because the provider may hand a different rule set to each user, role, or tenant.
:::

The same helpers are also reachable through the two bound guards, where the schema
is already fixed — drop the first argument:

```php
DocumentSchema::guard($user)->couldEverHave('update');     // schema-bound guard
$user->warrant()->couldEverHave(Document::class, 'update'); // user-bound guard
```

## Rendering UI without a query per link

Reachability is built for exactly this — deciding what to render before you ever
touch a row:

```php
use Warrant\Reachability;

match (Warrant::reachabilityOf(Document::class, 'update')) {
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
use Warrant\Middleware\WarrantMiddleware;

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
