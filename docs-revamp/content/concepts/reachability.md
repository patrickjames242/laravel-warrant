---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Reachability
description: Could this user ever? A structural question, answered with no conditions and no SQL.
sidebar:
  order: 7
---

Every check so far asks about rows. A different question asks about the rules
themselves: *could this user ever update a document, whatever the row?*

That is reachability. It reads the shape of the rule set your resolver returned. It
evaluates no conditions, runs no SQL, and takes no context, because context only
ever feeds condition evaluation.

It exists because rendering a page asks that question dozens of times. Whether to
draw the Edit column, whether the Admin section belongs in the nav, whether a route
is worth registering. None of those are about a row.

## Three states

The rule of thumb is unconditionality. A rule with an `if` is a maybe, because
whether it fires depends on a condition nobody evaluated here.

| `Warrant\Reachability` | When | What the UI does |
|---|---|---|
| `NEVER` | no `can` rule lists it, or an unconditional `cannot` forbids it | omit the control |
| `MAYBE` | a condition decides | show it, check per row |
| `ALWAYS` | an unconditional `can`, with no unconditional deny | show it, enabled |

Resolved top to bottom for one ability:

1. an unconditional `cannot` gives `NEVER`, since nothing dodges it;
2. no `can` rule lists it gives `NEVER`, since there is no grant path;
3. an unconditional `can` and no *conditional* `cannot` gives `ALWAYS`;
4. otherwise `MAYBE`.

Worked through three rule sets:

```warrant
they can view                      # view: ALWAYS
if is_mine they can update         # update: MAYBE
                                   # delete: NEVER (nothing grants it)
```

```warrant
they can *
if is_suspended they cannot *      # every ability: NEVER
```

```warrant
they can view
if is_locked they cannot view      # view: ALWAYS
```

That last one is the case worth understanding. A *conditional* `cannot` is ignored
on purpose, because a different row can dodge it. So `ALWAYS` means "granted by the
rules' shape", and it is not a promise that every row passes.

:::caution
`ALWAYS` is not a per-row guarantee. The per-row check remains the source of truth.
Reachability only tells you whether asking is worth the query.
:::

## A `can(...)` does not collapse

A rule containing a cross-schema `can(...)` or `check(...)` is treated like any
other conditional rule. Reachability is structural, so it never follows a hop into
another schema's rules to decide that something is certain.

## Asking

```php
use Warrant\Reachability;

Warrant::reachabilityOf(Document::class, 'update');   // NEVER | MAYBE | ALWAYS

Warrant::couldEverHave(Document::class, 'update');    // !== NEVER
Warrant::alwaysHas(Document::class, 'view');          // === ALWAYS
Warrant::neverHas(Document::class, 'delete');         // === NEVER

Warrant::possibleAbilities(Document::class);          // ['view', 'update']
Warrant::guaranteedAbilities(Document::class);        // ['view']
Warrant::impossibleAbilities(Document::class);        // ['delete']
```

A user is still required, even though no condition runs, because your resolver may
hand a different rule set to each user, role, or tenant.

The practical surface, including route guards and the `*Any` variants, is in
[checking reachability](/checking/reachability/).
