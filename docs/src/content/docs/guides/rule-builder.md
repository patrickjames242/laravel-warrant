---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The rule builder
description: The fluent WarrantRule::build() and WarrantRuleSet::build() front-ends — connectives, parenthesized groups, dynamic composition, and splicing DSL text.
sidebar:
  order: 3.5
---

The [rule language](/guides/rule-language/) is one way to author a rule; the
fluent **rule builder** is the other. When a rule's shape depends on runtime data
— a list of team ids, a feature flag, values that don't belong in a string —
`WarrantRule::build()` is often clearer than assembling DSL text.

It produces the **same AST** the parser does, so a built rule flows through
identical validation and compilation. Nothing is serialized to a string, so
arbitrary PHP values in condition parameters survive untouched.

```php
use Warrant\Rules\WarrantRule;

$rule = WarrantRule::build()
    ->if('is_self')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->theyCannot('delete')
    ->toRule();
```

That builds the same rule as:

```text
if is_self or (is_manager and in_region)
they can view, update
they cannot delete
```

## Connectives

Each connective has a plain and a negated form, mirroring Laravel's
`where`/`orWhere`/`whereNot`:

| Method | DSL equivalent |
| --- | --- |
| `if` / `andIf` | `and` (both are aliases; the first term's connective is ignored) |
| `orIf` | `or` |
| `ifNot` / `andIfNot` | `and not` |
| `orIfNot` | `or not` |

Each takes a condition name (with optional parameters) **or** a closure:

```php
->if('in_team', ['sales', 'eng'])   // condition with parameters
->orIf(fn ($c) => $c->if('a')->orIf('b')) // closure = a parenthesized group
```

A **closure is a parenthesized group**. It receives a bare condition builder — it
has `if`/`orIf`/… but no `theyCan`/`theyCannot`, because a group is only ever a
condition, never a whole rule.

## Precedence is identical to the DSL

`not` > `and` > `or`, so the two front-ends produce byte-for-byte identical trees.
`->if('a')->andIf('b')->orIf('c')` is `(a and b) or c`, not `a and (b or c)`. See
[operator precedence](/guides/rule-language/#operator-precedence) in the rule
language.

## Composing dynamically

Fold a list inside a group, or branch with `when()`:

```php
$rule = WarrantRule::build()
    ->if('is_self')
    ->orIf(function ($c) use ($teamIds) {
        foreach ($teamIds as $id) {
            $c->orIf('in_team', [$id]);
        }
    })
    ->when($includeManagers, fn ($c) => $c->orIf('is_manager'))
    ->theyCan('view')
    ->toRule();
```

An **empty group folds to `false`**, so it contributes nothing to an `or` and
vetoes an `and` — folding an empty list is a safe no-op.

## Splicing in DSL text

`ifRaw()` / `orIfRaw()` parse a DSL fragment and splice it in as one group —
author the readable part as text, compose the rest structurally:

```php
->ifRaw('is_admin or is_owner', $bindings = [])->andIf('in_region')
```

## Cross-schema `can` and `check`

The rule language's two cross-schema builtins — `can(...)` and `check(...)` — are
reachable structurally too. This is where the builder earns its keep most often,
because a row selector or a `with` value is usually a runtime value rather than
something you'd write into a string.

| Method | DSL equivalent |
| --- | --- |
| `ifCan` / `andIfCan` | `and can(...)` |
| `orIfCan` | `or can(...)` |
| `ifCheck` / `andIfCheck` | `and check(...)` |
| `orIfCheck` | `or check(...)` |

```php
use Warrant\Builders\Ref;
use Warrant\Rules\WarrantRule;

WarrantRule::build()
    ->if('is_author')
    ->orIfCan('approve', PayPeriod::class, Ref::context('period_id'))
    ->andIfCheck(
        fn ($p) => $p->if('is_open')->andIfNot('is_locked'),
        'pay_periods',
        Ref::column('timesheets', 'pay_period_id'),
    )
    ->theyCan('submit')
    ->toRule();
```

That builds the same rule as:

```text
if is_author
    or can(approve for pay_periods(@context period_id))
    and check(is_open and not is_locked for pay_periods(@column timesheets.pay_period_id))
they can submit
```

The schema may be given as a schema key, a schema instance or class-string, or a
model instance or class-string — the same references `WarrantRuleSet::fromRules()`
accepts.

### There are no negated variants

Negate a cross-schema term with a **group**, exactly as you would any other
sub-expression:

```php
->ifNot(fn ($c) => $c->ifCan('manage', 'departments', Ref::context('department_id')))
```

### Omitting the row means unbound

`can(view for folders)` asks a schema-wide question; `can(view for folders(<row>))`
asks about one row. Omit `$row` for the first — its default is a `NoRow` sentinel,
not `null`:

```php
->ifCan('access', 'billing')                      // can(access for billing)
->ifCan('view', 'folders', $folder->id)           // can(view for folders('f-1'))
->ifCan('view', 'folders', row: null)             // row-bound, and rejected by validate()
```

An explicit `null` stays **row-bound**, so a missing id (a `$folder?->id` that came
back null) fails loudly at validation instead of quietly widening the question. When
you're composing dynamically and want the unbound form as a fallback, say so:
`row: $id ?? new NoRow`.

A row selector may be a key, the target schema's own model, a `Ref`, a `BackedEnum`
or a `DateTimeInterface` — see
[what a row selector may be](/guides/rule-language/#what-a-row-selector-may-be).

### The `check` predicate

A string predicate is one condition of the **target** schema:

```php
->ifCheck('is_open', 'pay_periods', Ref::context('period_id'))
```

A closure receives a bare condition builder, for a boolean tree — and it's also the
form to use when a leaf takes parameters:

```php
->ifCheck(fn ($p) => $p->if('in_region', ['west'])->orIf('is_global'), 'pay_periods')
```

The closure must add at least one term. Unlike a group an empty predicate can't fall
back to `false` — a `check(...)` predicate may not contain a constant — so it throws
a `LogicException` instead.

## Symbolic references (`Ref`)

`Warrant\Builders\Ref` builds the DSL's three symbolic references, for use anywhere
the builder takes an argument value — a condition parameter, a row selector, or a
`with` map value:

| Factory | DSL |
| --- | --- |
| `Ref::context('year')` | [`@context year`](/guides/rule-language/#check-time-context-context) |
| `Ref::column('timesheets', 'pay_period_id')` | [`@column timesheets.pay_period_id`](/guides/rule-language/#column-references-column) |
| `Ref::sql('select id from pay_periods where closed = 0')` | `@sql "..."` |

They stay symbolic in the AST and resolve at compile time: a context ref per check,
a column ref against the registry and the query's grammar, a SQL ref verbatim. That
last one is emitted as written, so table scoping and injection are entirely yours —
see [raw SQL references](/guides/rule-language/#raw-sql-references-sql).

## A rule needs a clause

`toRule()` throws a `LogicException` if you call neither `theyCan` nor
`theyCannot` — exactly as the DSL rejects a bare `if` with no clause.

## Attaching a denial message

`theyCannotBecause()` denies and explains in one call, for when the `cannot`
fires. Each call adds one clause, so separate calls give separate abilities
separate messages, while abilities passed together share one:

```php
WarrantRule::build()
    ->if('is_locked')
    ->theyCannotBecause('update', 'This document is locked and can no longer be edited.')
    ->theyCannotBecause(['publish', 'delete'], 'Locked documents are read-only.')
    ->toRule();
```

To add a message to a rule you already have — one parsed from the DSL, say — use
`WarrantRule::withDenialMessage()`, which returns a copy. See
[Denial messages](/guides/denial-messages/) for the full behaviour.

## Building a whole rule set

A single rule rarely stands alone. `WarrantRuleSet::build()` hands you a `$rule`
factory: **each `$rule()` call starts a fresh rule** — with every connective above
— and adds it to the set. You never call `->toRule()` yourself; the set finalizes
each one for you.

```php
use Warrant\Rules\WarrantRuleSet;

$set = WarrantRuleSet::build('documents', function ($rule) {
    $rule()->if('is_self')->theyCan('view', 'update');

    $rule()->if('is_locked')
        ->theyCannotBecause('update', 'This document is locked and can no longer be edited.');

    $rule()->if('is_admin')->theyCan('view', 'update', 'delete');
});
```

The first argument is the schema — a model, a schema instance, or a schema-key
string. It's the terse equivalent of building each rule with `WarrantRule::build()`
and handing them to `WarrantRuleSet::fromRules()`, and it's the shape you'll most
often return from a [resolver](/guides/resolvers/).

---

The other rule-set constructors — `fromSyntax` and `fromRules` — live in
[Providing rules](/guides/resolvers/#building-a-rule-set), and every method
signature is in the [Rule-building API](/reference/rule-building-api/).
