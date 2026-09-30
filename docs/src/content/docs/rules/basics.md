---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rule basics
description: The anatomy of a rule, and the smallest grammar that gets useful work done.
sidebar:
  order: 1
---

A rule is an optional `if <expression>` followed by one or more clauses:

```warrant
if is_mine
they can view, update
they cannot delete
```

- `if <expression>` is optional. Without it the rule is unconditional and always
  applies.
- `they can <abilities>` grants. Abilities are comma-separated.
- `they cannot <abilities>` denies, and may carry a message.

`they` is always the current user, the one your resolver was asked about.

## Where one rule ends

Every `if` starts a new rule. Clauses attach to the nearest `if` above them.
Clauses written before any `if` form one leading unconditional rule.

```warrant
they can view                # rule 1, unconditional

if is_mine
they can update              # rule 2
they cannot delete           # also rule 2

if is_locked
they cannot update           # rule 3
```

Whitespace is cosmetic. That whole rule set on one line means exactly the same
thing.

## A worked example

Documents, with four rules that cover a realistic policy:

```warrant
they can view

if is_mine or manages_team
they can update

if is_locked and not is_admin
they cannot update because 'This document is locked.'

if is_admin
they can *
```

Read as English: everyone may view; owners and team managers may update; a locked
document may not be updated unless you are an admin; admins may do everything.

## Denial messages

Only a `cannot` may carry a reason, with `because` and a string literal:

```warrant
if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
they cannot delete because 'Locked documents cannot be deleted.'
```

Each clause carries its own, so one rule can deny two things for two reasons.
[Denial messages](/rules/denial-messages/) has the rest.

## Names and reserved words

Identifiers match `[A-Za-z_][A-Za-z0-9_-]*`. They start with a letter or
underscore and may contain letters, digits, underscores, and dashes. No dots.

These cannot be used as an exact ability or condition name: `if`, `they`, `can`,
`cannot`, `because`, `check`, `and`, `or`, `not`, `for`, `with`, `as`, `true`,
`false`, `null`. A name may still contain or start with one, so `canonical`,
`cannot_publish`, and `is_and_something` are all fine.

## Wildcards

`*` is every ability the schema declares, on both sides:

```warrant
if is_admin     they can *
if is_suspended they cannot *
```

`they cannot *` is the kill switch. Nothing can appeal it, so a suspended account
loses everything regardless of what any other rule grants.

## Syntax errors are eager and precise

A malformed rule throws `Warrant\DSL\Parsing\WarrantSyntaxException` at parse time,
with a caret, which stays readable even when the whole set is one line:

```text
Reserved word 'can' cannot be used as a name; expected an ability name. (line 1, column 21)

    if is_self they can can
                        ^
```

Names are checked later, when the set is validated or compiled against its schema,
because that is the first point anything knows what the schema declares.
