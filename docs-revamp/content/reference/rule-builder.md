---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The rule builder API
description: WarrantRuleBuilder, WarrantConditionBuilder, Ref, and NoRow.
sidebar:
  order: 7
---

Returned by `WarrantRule::build()` or `Warrant::rule()`. Conceptual coverage is in
[the PHP builder](/rules/builder/).

## Condition methods, from `WarrantConditionBuilder`

Each returns `static` and takes a condition name with optional parameters, **or** a
closure, which is a parenthesized group:

```php
->if(string|Closure $condition, array $parameters = [])
->andIf(...)     // alias of if; both mean `and`
->orIf(...)      // `or`
->ifNot(...)     // `and not`
->andIfNot(...)  // `and not`
->orIfNot(...)   // `or not`

->ifRaw(string $expression, array $bindings = [])     // splice a parsed fragment as one group
->orIfRaw(string $expression, array $bindings = [])

->when(mixed $condition, Closure $callback): static   // Laravel-style conditional
```

## Cross-schema methods

```php
->ifCan(string $ability, Model|WarrantSchema|string $schema, mixed $key = new NoRow, array $with = [])
->andIfCan(...)   // alias of ifCan
->orIfCan(...)

->ifCheck(string|Closure $predicate, Model|WarrantSchema|string $schema, mixed $key = new NoRow, array $with = [])
->andIfCheck(...)
->orIfCheck(...)
```

There are **no negated variants**. Negate with a group:

```php
->ifNot(fn ($c) => $c->ifCan('manage', 'departments', Ref::context('department_id')))
```

## Clause methods, from `WarrantRuleBuilder`

```php
->theyCan(string ...$abilities): static
->theyCannot(string ...$abilities): static
->theyCannotBecause(string|list<string> $abilities, string|Closure $message): static
->toRule(): WarrantRule
```

`theyCannotBecause()` adds one clause per call, so separate calls give separate
abilities separate messages while abilities passed together share one.

`toRule()` throws a `LogicException` if neither clause method was called.

## `NoRow` and `Ref`

```php
new Warrant\Builders\NoRow;                                            // the default $key: unbound

Warrant\Builders\Ref::context(string $key): ContextRef;                // @context <key>
Warrant\Builders\Ref::column(string $column): ColumnRef;               // @column <column>
Warrant\Builders\Ref::column(string $frame, string $column): ColumnRef; // @column <frame>.<column>
Warrant\Builders\Ref::sql(string $sql): SqlRef;                        // @sql "<sql>"
```

A `Ref` is valid anywhere the builder takes an argument value: a condition
parameter, a row selector, or a `with` map value.

## Semantics

- Precedence is `not`, then `and`, then `or`, identical to the language. The
  builder produces a byte-for-byte identical AST.
- A closure is a parenthesized group and receives a bare `WarrantConditionBuilder`,
  with no `theyCan` or `theyCannot`.
- An empty group folds to `false`: nothing in an `or`, a veto in an `and`.
- Condition parameters may be any PHP value. Nothing is stringified.
- Omitting `$key` gives an unbound handle. An explicit `key: null` stays row-bound
  and is rejected by `validate()`, so a missing id fails loudly instead of widening
  the question. For a dynamic fallback, write `key: $id ?? new NoRow`.
- A multi-part key takes a list, bound positionally to
  [`matchKey()`](/reference/warrant-schema/#matchkey).
- An empty `check` predicate closure throws a `LogicException`, since a predicate
  may not contain a constant and so cannot fall back to `false`.
- `$schema` is normalized through the registry, so a model or schema class-string
  resolving to nothing throws `OutOfBoundsException` at build time. A plain
  unregistered *key* string passes through and is caught by `validate()`.

## Examples

```php
use Warrant\Builders\Ref;
use Warrant\Rules\WarrantRule;

WarrantRule::build()
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

```php
WarrantRuleSet::build('documents', function ($rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');
    $rule()->if('is_locked')->theyCannotBecause('update', 'This document is locked.');
});
```

```php
->ifCan('access', 'billing')                                            // unbound
->ifCan('view', 'folders', $folder->id)                                 // row-bound
->ifCan('assign', 'shift_days', [Ref::column('team_id'), Ref::column('starts_on')])
->ifCheck('is_open', 'pay_periods', Ref::context('period_id'))
->ifCheck(fn ($p) => $p->if('in_region', ['west'])->orIf('is_global'), 'pay_periods')
```
