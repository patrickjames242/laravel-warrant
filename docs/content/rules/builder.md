---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The PHP builder
description: Build the same rules fluently, for shapes that depend on runtime data.
sidebar:
  order: 10
---

The language is one way to author a rule. The builder is the other, and it is
usually clearer when the rule's shape depends on runtime data: a list of team ids,
a feature flag, values that have no business being serialized into a string.

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;

$rule = WarrantRuleNode::build()
    ->if('is_mine')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->theyCannot('delete')
    ->toRule();
```

That is the same rule as:

```warrant
if is_mine or (is_manager and in_region)
they can view, update
they cannot delete
```

It produces the same AST the parser does, so a built rule goes through identical
validation and compilation. Nothing is serialized, so arbitrary PHP values in
condition parameters survive untouched.

:::tip[One front door]
Every construct is reachable from the facade. `parse` reads rule text of any form
and the result says which form it held; `condition` and `rule` hand you a builder:

```php
Warrant::condition()                                      // WarrantConditionBuilder
Warrant::rule()                                           // WarrantRuleBuilder
Warrant::parse('is_owner or is_admin')->conditionExpression()
Warrant::parse('if is_mine they can view')->rule()
Warrant::parse('for documents { they can view }')->ruleSet()
Warrant::parse('for documents { … } for folders { … }')->forSchema('folders')
```

`warrant($syntax, $bindings)` and `warrant_file($path, $bindings)` are global
helpers for `Warrant::parse()` and `Warrant::parseFile()`.

`Warrant::rule()` and `WarrantRuleNode::build()` are the same call.
:::

## Connectives

Each has a plain and a negated form, mirroring `where` / `orWhere` / `whereNot`:

| Method | Language |
| --- | --- |
| `if` / `andIf` | `and`, and the first term's connective is ignored |
| `orIf` | `or` |
| `ifNot` / `andIfNot` | `and not` |
| `orIfNot` | `or not` |

Each takes a condition name with optional parameters, or a closure:

```php
->if('in_team', ['sales', 'eng'])
->orIf(fn ($c) => $c->if('a')->orIf('b'))
```

A closure is a parenthesized group. It receives a bare condition builder, with the
connectives but no `theyCan` or `theyCannot`, because a group is only ever a
condition.

Precedence is identical to the language, `not` then `and` then `or`, so
`->if('a')->andIf('b')->orIf('c')` is `(a and b) or c`.

## Where it earns its keep

Folding a list:

```php
$rule = WarrantRuleNode::build()
    ->if('is_mine')
    ->orIf(function ($c) use ($teamIds) {
        foreach ($teamIds as $id) {
            $c->orIf('in_team', [$id]);
        }
    })
    ->when($includeManagers, fn ($c) => $c->orIf('is_manager'))
    ->theyCan('view')
    ->toRule();
```

An empty group folds to `false`, so it contributes nothing to an `or` and vetoes an
`and`. Folding an empty list is a safe no-op.

Passing a value the language cannot express inline:

```php
WarrantRuleNode::build()
    ->if('within_polygon', [$geoJsonArray])
    ->theyCan('view')
    ->toRule();
```

## Splicing in text

Author the readable part as a string and compose the rest structurally:

```php
->ifRaw('is_admin or is_owner', $bindings = [])->andIf('in_region')
```

## Cross-schema references

This is the other place the builder wins, because a row selector or a `with` value
is usually a runtime value:

```php
use Warrant\Builders\Ref;

WarrantRuleNode::build()
    ->if('is_author')
    ->orIfCan('approve', PayPeriod::class, Ref::context('period_id'))
    ->andIfCheck(
        fn ($p) => $p->if('is_open')->andIfNot('is_locked'),
        'pay_periods',
        Ref::column('pay_period_id'),
    )
    ->theyCan('submit')
    ->toRule();
```

| Method | Language |
| --- | --- |
| `ifCan` / `andIfCan` / `orIfCan` | `can(...)` |
| `ifCheck` / `andIfCheck` / `orIfCheck` | `check(...)` |

Three things to remember.

**There are no negated variants.** Negate with a group:

```php
->ifNot(fn ($c) => $c->ifCan('manage', 'departments', Ref::context('department_id')))
```

**Omitting the row means unbound.** Its default is a `NoRow` sentinel, not `null`:

```php
->ifCan('access', 'billing')                 // can(access for billing)
->ifCan('view', 'folders', $folder->id)      // can(view for folders('f-1'))
->ifCan('view', 'folders', key: null)        // row-bound, and rejected by validate()
```

An explicit `null` stays row-bound, so a missing id fails loudly instead of quietly
widening the question. When composing dynamically and wanting the unbound form as a
fallback, say so: `key: $id ?? new NoRow`.

**A multi-part key takes a list**, bound positionally to `matchKey()`:

```php
->ifCan('assign', 'shift_days', [Ref::column('team_id'), Ref::column('starts_on')])
```

A `check` predicate may be a single condition name, or a closure for a tree, which
is also the form to use when a leaf takes parameters:

```php
->ifCheck('is_open', 'pay_periods', Ref::context('period_id'))
->ifCheck(fn ($p) => $p->if('in_region', ['west'])->orIf('is_global'), 'pay_periods')
```

The closure must add at least one term. Unlike a group it cannot fall back to
`false`, because a predicate may not contain a constant, so it throws a
`LogicException`.

## Symbolic references

`Ref` builds the three symbolic references for use anywhere the builder takes an
argument value:

| Factory | Language |
| --- | --- |
| `Ref::context('year')` | `@context year` |
| `Ref::column('pay_period_id')` | `@column pay_period_id` |
| `Ref::column('timesheets', 'pay_period_id')` | `@column timesheets.pay_period_id` |
| `Ref::sql('select id from pay_periods where closed = 0')` | `@sql "..."` |

They stay symbolic in the AST and resolve at compile time: a context ref per check,
a column ref against the registry and the query's grammar, a SQL ref verbatim.

## A rule needs a clause

`toRule()` throws a `LogicException` if you call neither `theyCan` nor
`theyCannot`, exactly as the language rejects a bare `if` with no clause.

## A whole rule set

`RuleSetNode::build()` hands you a factory. Each `$rule()` call starts a fresh
rule and adds it to the set, and you never call `toRule()` yourself:

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;

$set = RuleSetNode::build('documents', function ($rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');

    $rule()->if('is_locked')
        ->theyCannotBecause('update', 'This document is locked.');

    $rule()->if('is_admin')->theyCan('view', 'update', 'delete');
});
```

This is the shape you will most often return from a
[provider](/supplying-rules/provider/).

## Why the compiler checks the builder too

A rule built this way never passes through the parser, so no pass over rule text
can see it. That is why the compiler carries its own copy of every check the
validator makes, rather than trusting that validation already ran. See
[the validator is never stricter than the
compiler](/sql/validator-compiler-invariant/).
