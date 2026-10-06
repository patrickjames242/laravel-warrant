---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Combining conditions
description: and, or, not, parentheses, precedence, and what happens when an operand is unknown.
sidebar:
  order: 3
---

The `if` expression is a boolean combination of conditions:

```warrant
if is_mine or is_manager
if is_mine and not is_locked
if is_manager and (in_team('sales') or in_team('eng'))
if not (is_archived or is_locked)
```

`and` and `or` are binary. `not` negates, and `!` is an accepted synonym, though
`not` is the canonical spelling. Parentheses group.

`&&` and `||` are not supported.

## Precedence

Tightest to loosest: `not` and `!`, then `and`, then `or`. So this:

```warrant
if is_mine or not is_manager and is_owner
```

parses as:

```warrant
if is_mine or ((not is_manager) and is_owner)
```

When in doubt, parenthesize. The parenthesized form compiles identically, so there
is no cost to being explicit.

## What each one becomes

Boolean structure becomes nested `WHERE` groups, and negation is pushed to the
leaves by De Morgan. A `not` lands on a single condition, never on a group:

```warrant
if is_locked and not is_admin they cannot update
```

The deny side is `NOT(is_locked AND NOT is_admin)`, which De Morgan turns into
`(NOT is_locked OR is_admin)`. For an admin, `is_admin` is `true` in PHP, the
branch folds away, and the whole denial disappears from the query.

## Unknown operands

This is where the third truth value shows up in everyday rules. An unknown
operand behaves the way SQL says it does:

| Expression | Result |
|---|---|
| `unknown and false` | `false` |
| `unknown and true` | `unknown` |
| `unknown or true` | `true` |
| `unknown or false` | `unknown` |
| `not unknown` | `unknown` |

Read those twice if a rule is behaving strangely. `unknown and true` staying
unknown is why one broken condition can take an entire `and` chain down with it,
and `not unknown` staying unknown is why an unanswerable condition under a `cannot`
blocks rather than lifts. See [true, false, and
unknown](/concepts/three-truth-values/).

## Parentheses you did not write

The compiler drops grouping that carries no meaning. A group holding one branch is
not wrapped, and a condition that emitted a single `where` is spliced in bare
rather than nested in a group of its own. So nesting you see in the emitted SQL
reflects real boolean structure, which makes the output worth reading when
diagnosing.

## Longer expressions

Conditions, cross-schema references, and their negations are all ordinary leaves,
so they combine the same way:

```warrant
if is_author
    and check(is_open for pay_periods(@column pay_period_id))
    and not can(freeze for pay_periods(@context period_id))
they can submit
```

If an expression is getting long enough to be hard to read, that is usually a sign
the shape wants a name. A condition built from other conditions is
[a derived condition](/schemas/conditions-beyond-sql/):

```php
#[DerivedCondition]
public function isEditable(): string
{
    return 'is_submitted and not is_approved and not is_locked';
}
```

```warrant
if is_editable they can update
```
