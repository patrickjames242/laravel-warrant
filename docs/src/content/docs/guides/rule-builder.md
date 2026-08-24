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
use Warrant\RuleSyntaxTree\WarrantRule;

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

## A rule needs a clause

`toRule()` throws a `LogicException` if you call neither `theyCan` nor
`theyCannot` — exactly as the DSL rejects a bare `if` with no clause.

## Attaching a denial message

`withDenialMessage()` is available mid-chain, to explain a `cannot` when it fires:

```php
WarrantRule::build()
    ->if('is_locked')->theyCannot('update')
    ->withDenialMessage('This document is locked and can no longer be edited.')
    ->toRule();
```

See [Denial messages](/guides/denial-messages/) for the full behaviour.

## Building a whole rule set

A single rule rarely stands alone. `WarrantRuleSet::build()` hands you a `$rule`
factory: **each `$rule()` call starts a fresh rule** — with every connective above
— and adds it to the set. You never call `->toRule()` yourself; the set finalizes
each one for you.

```php
use Warrant\RuleSyntaxTree\WarrantRuleSet;

$set = WarrantRuleSet::build('documents', function ($rule) {
    $rule()->if('is_self')->theyCan('view', 'update');

    $rule()->if('is_locked')->theyCannot('update')
        ->withDenialMessage('This document is locked and can no longer be edited.');

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
