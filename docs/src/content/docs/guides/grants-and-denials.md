---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Grants and denials
description: How Warrant combines can and cannot rules, and the corner cases that follow.
sidebar:
  order: 4
---

Warrant combines every `can` and `cannot` — across all rules, including
[implicit rules](/guides/resolvers/#implicit-rules) — with one rule: **a `cannot`
always beats a `can`**. For a given ability the compiled predicate is:

```text
predicate(ability) =
    ( OR of each `can` rule's if-expression )
    AND ( AND of NOT(each `cannot` rule's if-expression) )
```

Because it's a symmetric AND/OR combination, **rule order never matters**. You can
merge rules from a resolver, implicit rules, and multiple clauses in any order.

## The hard edges

| Situation | Compiles to | Meaning |
|---|---|---|
| Unconditional `cannot` | `AND NOT(true)` → `1 = 0` | This user can *never* have the ability, on any row |
| No `can` rule for the ability | `1 = 0` | Denied by default — silence is not permission |
| Unconditional `can` | `1 = 1` term | This user has the ability on every row |
| Conditional `cannot` | `AND NOT(condition)` | Subtracts matching rows from the grant |

## A `cannot` is an absolute veto

No `can` rule can bring back an ability a `cannot` denies. This is what makes the
"kill switch" idiom work — grant everything, but a suspended user loses all of it:

```text
they can *
if is_suspended
they cannot *
```

Since the combination is order-independent, an **implicit** `cannot` beats any
resolver-supplied `can`:

```php
protected function implicitRules(): array|WarrantRuleSet
{
    return [
        WarrantRule::fromSyntax('if is_suspended they cannot *'),
    ];
}
```

## "Unless" is just `and not`

Exceptions read naturally as a guard on the deny:

```text
if is_self or manages_team they can update
if is_locked and not is_admin they cannot update
if is_admin they can *
```

The middle line — "never once it's locked, *unless* they're an admin" — is a
`cannot update` guarded by `and not is_admin`. No early returns, no ordering
tricks.

## Silence denies — a common surprise

If no rule mentions an ability with a `can`, that ability is denied, full stop.
This trips people who expect an unlisted ability to be "allowed by default." It
isn't. Every ability a user should have needs a matching `can`.

## Interaction with missing context

An **optional** `@context` key that's absent at check time is passed to its
condition as `null`, and standard SQL logic takes over. A comparison against `null`
is `UNKNOWN`, which contributes no access: on a `can` it doesn't grant, and on a
`cannot` the `AND NOT(UNKNOWN)` term drops the row — so an absent key makes the veto
err toward *denying* (fail-closed), never toward silently granting. It's still good
practice to declare a context key that gates a `cannot` as `required`, so a missing
frame fails loudly rather than quietly blocking rows. See
[Check-time context](/guides/context/).
