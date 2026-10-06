---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Reachability
description: Could this user ever? Answered from the rules alone, with no row and no SQL.
sidebar:
  order: 7
---

Every check so far asks about rows. A different question asks about the rules
themselves: *could this user ever update a document, whatever the row?*

That is reachability. It reads the rule set your resolver returned, asked without a
row. It runs no SQL and never evaluates a row or global condition, and it takes no
context, because context only ever feeds those conditions. Everything that can be
answered without a row, it follows.

It exists because rendering a page asks that question dozens of times. Whether to
draw the Edit column, whether the Admin section belongs in the nav, whether a route
is worth registering. None of those are about a row.

## Three states

| `Warrant\Reachability` | When | What the UI does |
|---|---|---|
| `NEVER` | no way the rules can come out grants it | omit the control |
| `MAYBE` | a condition decides | show it, check per row |
| `ALWAYS` | every way the rules can come out grants it | show it, enabled |

## How an ability is judged

Reachability combines an ability's rules the way the
[compiler](/sql/rule-to-query/) does: the `can` rules ORed together, then ANDed
with the negation of every `cannot`. It has no row, so for each part of a condition
it tracks every value that part could still take: true, false, or
[unknown](/sql/unknown/).

- **A row or global condition** (`is_mine`, `is_admin`) could be anything. It is
  never evaluated here.
- **A [derived condition](/schemas/conditions-beyond-sql/)** is read through. If it
  answers `true`, it is always true. If it answers `false`, it is never true. If it
  answers `null`, it is unknown, which never grants. If it answers with an
  expression, that expression is judged by these same rules.
- **`can(x)` or `can(x for other_schema)`** is whatever ability `x` comes out as,
  judged the same way, in the rules this user is given for that schema.
- **`check(... for other_schema)`** is whatever its predicate comes out as. A
  `can(...)` inside the predicate asks about an ability of the schema it checks.
- **A row-bound reference** (`can(x for folders(@column folder_id))`) is as above,
  but the row may not exist. So it can rule a grant out, never guarantee one.

The ability is held only where the whole comes out true. If true is impossible,
the answer is `NEVER`. If only true is possible, it is `ALWAYS`. Anything else is
`MAYBE`.

Worked through a few rule sets:

```warrant
they can view                      # view: ALWAYS
if is_mine they can update         # update: MAYBE
                                   # delete: NEVER (nothing grants it)
```

```warrant
they can *
they cannot *                      # every ability: NEVER
```

```warrant
they can view
if is_locked they cannot view      # view: MAYBE (some rows are locked)
```

An ability that leans on another depends on how the two are combined:

```warrant
if can(manage for folders) or is_mine they can edit     # MAYBE, even if nothing grants manage
if can(manage for folders) and is_mine they can edit    # NEVER, if nothing grants manage to this user
if can(publish) they can view                           # ALWAYS, if publish is granted unconditionally
```

The same goes for denies. A `cannot` whose condition can never be true leaves an
unconditional grant `ALWAYS`. A `cannot` that hinges on an ability the user always
has is as good as unconditional, so the result is `NEVER`.

When the analysis is unsure it answers `MAYBE`, never a wrong `NEVER` or `ALWAYS`.
That covers an ability that refers back to itself (a cycle, which the compiler
rejects), a schema whose rules cannot be resolved, and a condition that appears
twice (`a or not a` is judged as though the two were independent).

:::caution
`ALWAYS` is a guarantee about the rules, not about a row: no row, context or
condition outcome can make the rules deny it. The per-row check remains the source
of truth. Reachability only tells you whether asking is worth the query.
:::

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

A user is still required, even though no row or global condition runs, because your resolver may
hand a different rule set to each user, role, or tenant.

The practical surface, including route guards and the `*Any` variants, is in
[checking reachability](/checking/reachability/).
