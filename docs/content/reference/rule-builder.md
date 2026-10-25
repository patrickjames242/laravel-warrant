---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The rule builder API
description: Build, the rule and condition builders, can and check references, Ref, and NoRow.
sidebar:
  order: 7
---

Every class here is in `Warrant\Builders`. Conceptual coverage is in
[the PHP builder](/rules/builder/).

## `Build`

```php
Build::rule(): WarrantRuleBuilder
Build::condition(): WarrantConditionBuilder
Build::ruleSet(string $schemaKey, Closure(RuleSetBuilder): mixed $callback): RuleSetNode
Build::ruleSet(Closure(RuleSetBuilder): mixed $callback): list<IRuleEntryNode>   // headless
Build::can(string $ability): CrossSchemaCanBuilder
Build::check(?string $condition = null, array $parameters = []): CrossSchemaCheckBuilder
```

`rules()`, a `RuleProvider`, a `#[RuleTemplate]` and a `#[DerivedCondition]`
accept the builders as they are, alone or in a list, and finish them.

## Condition methods, from `WarrantConditionBuilder`

Each returns `static`.

```php
->if(string|Closure|bool|WarrantConditionBuilder|IBooleanExpressionNode|CrossSchemaCanBuilder $condition, array $parameters = [])
->andIf(...)     // alias of if; both mean `and`
->orIf(...)      // `or`
->ifNot(...)     // `and not`
->andIfNot(...)  // `and not`
->orIfNot(...)   // `or not`
```

| `$condition` | Means |
| --- | --- |
| `string` | a condition name, taking `$parameters` |
| `Closure(WarrantConditionBuilder): mixed` | a parenthesized group |
| `bool` | a constant |
| `WarrantConditionBuilder` | a group; an empty one is `false` |
| `IBooleanExpressionNode` | that expression, as it is |
| `CrossSchemaCanBuilder` / `CrossSchemaCheckBuilder` | its `can(...)` or `check(...)` |

```php
->ifRaw(string $expression, array $bindings = [])        // and (...)
->andIfRaw(string $expression, array $bindings = [])     // and (...)
->orIfRaw(string $expression, array $bindings = [])      // or (...)
->ifNotRaw(string $expression, array $bindings = [])     // and not (...)
->andIfNotRaw(string $expression, array $bindings = [])  // and not (...)
->orIfNotRaw(string $expression, array $bindings = [])   // or not (...)

->when(mixed $value, Closure $callback, ?Closure $default = null): static
->unless(mixed $value, Closure $callback, ?Closure $default = null): static

->hasTerms(): bool
->buildConditions(SyntaxSource $source): ?IBooleanExpressionNode
```

## Cross-schema methods

```php
->ifCan(string|CrossSchemaCanBuilder|Closure(Closure(string): CrossSchemaCanBuilder): mixed $ability)
->andIfCan(...)   // alias of ifCan
->orIfCan(...)

->ifCheck(CrossSchemaCheckBuilder|Closure(CrossSchemaCheckBuilder): mixed $check)
->andIfCheck(...)
->orIfCheck(...)
```

A string given to `ifCan` is an ability on the same schema, `can(edit)`. Negate a
reference by passing it to `ifNot`, `andIfNot` or `orIfNot`.

## `CrossSchemaCanBuilder` and `CrossSchemaCheckBuilder`

Both name their target the same way:

```php
->for(Model|WarrantSchema|string $schema, mixed $key = new NoRow): static
->as(string $alias): static
->with(array $with): static
->toNode(SyntaxSource $source): CrossSchemaCanNode|CrossSchemaConditionNode
```

`CrossSchemaCheckBuilder` extends `WarrantConditionBuilder`: its condition terms
are the predicate. It adds:

```php
->assertComplete(): static   // throws unless for() was called and the predicate has a term
```

## Can/cannot clause methods, from `WarrantRuleBuilder`

```php
->theyCan(string ...$abilities): static
->theyCannot(string ...$abilities): static
->theyCannotBecause(string|list<string> $abilities = [], string|Closure|null $message = null): static
->toRule(SyntaxSource $source = new SyntaxSource(null)): WarrantRuleNode
```

`theyCannotBecause()` adds one cannot clause per call, so separate calls give separate
abilities separate messages while abilities passed together share one. With no
abilities, a can/cannot clause is generic, as a rule template's body writes it; pass the
message by name, `theyCannotBecause(message: '...')`. The message is required: a
call without one throws a `LogicException`, so a lone ability is never read as a
message.

`toRule()` throws a `LogicException` if no can/cannot clause method was called.

## `RuleSetBuilder` and `IncludeInvocationBuilder`

The `$rule` a `Build::ruleSet()` callback receives:

```php
$rule(): WarrantRuleBuilder                                              // a new rule
$rule->include(string $template, array $arguments = []): IncludeInvocationBuilder
$rule->entries(SyntaxSource $source): list<IRuleEntryNode>

// IncludeInvocationBuilder
->for(string ...$abilities): static     // leave it out for a generic include
->toNode(SyntaxSource $source): IncludeInvocationNode
```

## `NoRow` and `Ref`

```php
new NoRow;                                            // the default $key: unbound

Ref::context(string $key): ContextRef;                // @context <key>
Ref::column(string $column): ColumnRef;               // @column <column>
Ref::column(string $frame, string $column): ColumnRef; // @column <frame>.<column>
Ref::sql(string $sql): SqlRef;                        // @sql "<sql>"
```

A `Ref` is valid anywhere the builder takes an argument value: a condition
parameter, an include argument, a row selector, or a `with` map value.

## Semantics

- A builder holds what it was given and makes its nodes when it is resolved, in
  the source of the syntax it is handed over in. A builder used twice is resolved
  twice, as two trees.
- Precedence is `not`, then `and`, then `or`, identical to the language. The
  builder produces a byte-for-byte identical AST.
- A closure is a parenthesized group and receives a bare `WarrantConditionBuilder`,
  with no `theyCan` or `theyCannot`. Group, `ifCheck` and `ifCan` closures run
  where they are written, so their mistakes throw there.
- An empty group folds to `false`: nothing in an `or`, a veto in an `and`.
- Condition parameters may be any PHP value. Nothing is stringified.
- Only a condition name takes `$parameters`; with anything else they throw a
  `LogicException`. A `WarrantRuleBuilder` given as a condition throws one too.
- Omitting `$key` gives an unbound handle. An explicit `null` stays row-bound and is
  rejected by validation, so a missing id fails loudly instead of widening the
  question. For a dynamic fallback, write `->for('folders', $id ?? new NoRow)`.
- A multi-part key takes a list, bound positionally to
  [`matchKey()`](/reference/warrant-schema/#matchkey).
- A check with no `for()`, or an empty predicate, throws a `LogicException` where
  it is written, since a predicate may not contain a constant and so cannot fall
  back to `false`.
- The `ifCan` factory must be called exactly once, or it throws a `LogicException`.
- `$schema` is normalized through the registry, so a model or schema class-string
  resolving to nothing throws `OutOfBoundsException` at build time. A plain
  unregistered *key* string passes through and is caught by validation.
- Raw text is parsed where it is written, and keeps its own producer, a raw
  condition, wherever the rule is handed over.

## Examples

```php
use Warrant\Builders\Build;
use Warrant\Builders\Ref;

Build::rule()
    ->if('is_author')
    ->orIfCan(Build::can('approve')->for(PayPeriod::class, Ref::context('period_id')))
    ->andIfCheck(fn ($check) => $check
        ->if('is_open')
        ->andIfNot('is_locked')
        ->for('pay_periods', Ref::column('pay_period_id')))
    ->theyCan('submit');
```

```php
Build::ruleSet('documents', function ($rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');
    $rule()->if('is_locked')->theyCannotBecause('update', 'This document is locked.');
    $rule->include('audit_trail')->for('*');
});
```

```php
->ifCan('edit')                                                                   // same schema
->ifCan(Build::can('access')->for('billing'))                                     // unbound
->ifCan(Build::can('view')->for('folders', $folder->id))                          // row-bound
->ifCan(Build::can('assign')->for('shift_days', [Ref::column('team_id'), Ref::column('starts_on')]))
->ifCan(fn ($can) => $can('manage')->for('departments', Ref::column('department_id'))->as('d'))
->ifCheck(Build::check('is_open')->for('pay_periods', Ref::context('period_id')))
->ifCheck(fn ($check) => $check->if('in_region', ['west'])->orIf('is_global')->for('pay_periods'))
->ifNot(Build::check('is_closed')->for('pay_periods', Ref::column('pay_period_id')))
```
