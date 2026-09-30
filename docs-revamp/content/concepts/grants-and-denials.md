---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Grants, denials, and appeals
description: How can and cannot combine, why order never matters, and why a cannot cannot be appealed.
sidebar:
  order: 4
---

Every `can` and every `cannot` across the whole rule set, including
[implicit rules](/schemas/schema-policy/), combine into one predicate per ability:

```text
predicate(ability) =
    ( OR of each `can` rule's if-expression )
    AND ( AND of NOT(each `cannot` rule's if-expression) )
```

An `AND` of `OR`s has no order in it, so **rule order never matters**. You can
concatenate rules from a role table, a team table, a schema's implicit rules, and a
hard-coded string in any sequence and get the same answer.

## The four edges

| Written | Compiles to | Means |
|---|---|---|
| unconditional `cannot` | `AND NOT(true)`, so `false` | never, on any row |
| no `can` rule for the ability | an empty `OR`, so `false` | denied by default |
| unconditional `can` | an always-true term | on every row |
| conditional `cannot` | `AND NOT(condition)` | subtracts matching rows |

Those are real booleans while the predicate is assembled, so they fold away rather
than appearing as `1 = 1` in the emitted SQL.

## Silence denies

This is the edge people trip over. An ability nobody mentioned is denied:

```warrant
if is_mine they can view, update
```

```php
Warrant::can('delete', $myDocument);   // false
```

Nothing forbade `delete`. Nothing granted it either, and that is enough. Every
ability a user should have needs a matching `can` somewhere.

## A `cannot` cannot be appealed

No `can` anywhere brings back an ability a matching `cannot` took away. That is
what makes the kill switch work:

```warrant
they can *

if is_suspended
they cannot *
```

Grant everything, then take everything back from a suspended account. Because
order does not matter, it does not matter that the grant was written first, and it
does not matter which source each rule came from. An implicit `cannot` declared on
the schema beats any `can` a resolver returns:

```php
protected function implicitRules(): array|WarrantRuleSet
{
    return [
        WarrantRule::fromSyntax('if is_suspended they cannot *'),
    ];
}
```

## "Unless" is `and not`

There is no appeal, so an exception is written as a guard on the denial itself:

```warrant
if is_mine or manages_team they can update

if is_locked and not is_admin
they cannot update

if is_admin they can *
```

The middle rule reads "never once it is locked, unless they are an admin". It
compiles to `NOT(is_locked AND NOT is_admin)`, which De Morgan turns into
`(NOT is_locked OR is_admin)`. For an admin that whole branch is true and the
denial disappears.

This works, and it has a ceiling: the exception has to be written into the denial,
by whoever owns the denial. A rule set assembled from three sources cannot have the
third source carve an exception out of the first source's `cannot`. Locally
overridable denials are [on the roadmap](/roadmap/planned/).

## Wildcards

`*` means every ability the schema declares, on both sides:

```warrant
if is_admin    they can *
if is_suspended they cannot *
```

An ability added to the schema later is picked up by both without editing a rule.

## Unknown is not a failed denial

A third outcome sits beside granted and denied. If a `cannot` cannot be evaluated,
the ability is not granted either:

```warrant
they can view
if is_owner they cannot view
```

Asked with no row at all, `is_owner` is unanswerable, so that reports nothing. An
unknown deny is not a deny that failed to fire. See
[true, false, and unknown](/concepts/three-truth-values/).
