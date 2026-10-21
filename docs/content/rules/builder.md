---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The PHP builder
description: Build the same rules fluently, for shapes that depend on runtime data.
sidebar:
  order: 11
---

The language is one way to author a rule. The builder is the other, and it is
usually clearer when the rule's shape depends on runtime data: a list of team ids,
a feature flag, values that have no business being serialized into a string.

```php
use Warrant\Builders\Build;

Build::rule()
    ->if('is_mine')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->theyCannot('delete');
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

## The way in

Everything starts from `Warrant\Builders\Build`:

| Call | Makes |
| --- | --- |
| `Build::rule()` | a rule: conditions, then `theyCan` / `theyCannot` |
| `Build::condition()` | a condition expression, with no clauses |
| `Build::ruleSet('documents', fn ($rule) => ...)` | a `for documents` rule set |
| `Build::ruleSet(fn ($rule) => ...)` | the entries alone, as a rule template writes them |
| `Build::can('approve')` | a `can(...)` reference |
| `Build::check('is_open')` | a `check(...)` reference |

Everything that accepts rule text accepts these too: `rules()`, a
[provider](/supplying-rules/provider/), a [rule template](/rules/templates/) and
a [derived condition](/schemas/conditions-beyond-sql/). Return the builder as it
is; Warrant finishes it.

:::tip[A builder makes its nodes when it is handed over]
A builder holds what you gave it and makes no nodes until it is resolved, in the
syntax it was handed over in. So a condition you build once and use in two rules
becomes two separate trees, and every node of each says where it came from:
`rules()`, the provider, a template or a derived condition.
:::

## Connectives

Each has a plain and a negated form, mirroring `where` / `orWhere` / `whereNot`:

| Method | Language |
| --- | --- |
| `if` / `andIf` | `and`, and the first term's connective is ignored |
| `orIf` | `or` |
| `ifNot` / `andIfNot` | `and not` |
| `orIfNot` | `or not` |

Each takes any of these:

```php
->if('in_team', ['sales', 'eng'])            // a condition name, with parameters
->orIf(fn ($c) => $c->if('a')->orIf('b'))    // a closure: a parenthesized group
->andIf($featureEnabled)                     // a bool, folded in as a constant
->andIf($visible)                            // a condition builder, spliced in as a group
->andIf($node)                               // an expression node, used as it is
->ifNot(Build::can('manage')->for(...))      // a can(...) or check(...) reference
```

A closure receives a bare condition builder, with the connectives but no
`theyCan` or `theyCannot`, because a group is only ever a condition. Its return
value is ignored.

Only a condition name takes parameters. Passing them with anything else throws a
`LogicException`, and so does passing a rule builder, because a rule is not a
condition.

Precedence is identical to the language, `not` then `and` then `or`, so
`->if('a')->andIf('b')->orIf('c')` is `(a and b) or c`.

## Reusing a condition

Build a condition once and splice it into as many rules as you like:

```php
$visible = Build::condition()->if('is_public')->orIf('is_mine');

return [
    Build::rule()->if($visible)->andIfNot('is_archived')->theyCan('view'),
    Build::rule()->ifNot($visible)->theyCannotBecause('view', 'You cannot see this document.'),
];
```

An empty condition builder splices in as `false`.

## Where it earns its keep

Folding a list:

```php
Build::rule()
    ->if('is_mine')
    ->orIf(function ($c) use ($teamIds) {
        foreach ($teamIds as $id) {
            $c->orIf('in_team', [$id]);
        }
    })
    ->when($includeManagers, fn ($r) => $r->orIf('is_manager'))
    ->theyCan('view');
```

An empty group folds to `false`, so it contributes nothing to an `or` and vetoes an
`and`. Folding an empty list is a safe no-op.

Passing a value the language cannot express inline:

```php
Build::rule()
    ->if('within_polygon', [$geoJsonArray])
    ->theyCan('view');
```

## Runtime branches

`when()` and `unless()` follow Laravel: the callback runs when the value is truthy
(or falsy, for `unless`), and an optional third callback runs otherwise. Each is
handed the same builder, so it may add conditions and clauses alike:

```php
Build::rule()
    ->if('is_manager')
    ->when($isSenior,
        fn ($r) => $r->theyCan('view', 'approve', 'reopen'),
        fn ($r) => $r->theyCan('view', 'approve'))
    ->unless($readOnly, fn ($r) => $r->theyCan('update'));
```

## Splicing in text

Author the readable part as a string and compose the rest structurally. Each raw
method splices its text in as one group:

| Method | Language |
| --- | --- |
| `ifRaw` / `andIfRaw` | `and (...)` |
| `orIfRaw` | `or (...)` |
| `ifNotRaw` / `andIfNotRaw` | `and not (...)` |
| `orIfNotRaw` | `or not (...)` |

```php
Build::rule()
    ->ifRaw('is_admin or is_owner')
    ->andIfRaw('in_region(:region)', ['region' => 'west'])
    ->orIfNotRaw('is_archived')
    ->theyCan('update');
```

The text is parsed where you write it, so a mistake in it throws there.

## Cross-schema references

This is the other place the builder wins, because a row selector or a `with` value
is usually a runtime value:

```php
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

| Method | Language |
| --- | --- |
| `ifCan` / `andIfCan` / `orIfCan` | `can(...)` |
| `ifCheck` / `andIfCheck` / `orIfCheck` | `check(...)` |

A reference names its target the same way for both:

```php
->for($schema, $key)        // the schema, and the row it selects
->as('pp')                  // names the rows it selects
->with(['year' => 2026])    // context handed to the target schema
```

`$schema` is a schema key, a schema, or a model, as a class or an instance. It is
resolved through the registry where you write it.

### `can(...)`

`ifCan` takes a string, a builder, or a closure:

```php
->ifCan('edit')                                                // can(edit): this same schema
->ifCan(Build::can('access')->for('billing'))                  // can(access for billing)
->ifCan(fn ($can) => $can('view')->for('folders', $folder->id))
```

The closure is handed a factory that starts a reference from its ability. Call it
exactly once; calling it never, or twice, throws a `LogicException`.

### `check(...)`

`ifCheck` takes a builder, or a closure handed a fresh one to configure:

```php
->ifCheck(Build::check('is_open')->for('pay_periods', Ref::context('period_id')))
->ifCheck(Build::check('in_region', ['west'])->for('pay_periods'))
->ifCheck(fn ($check) => $check->if('in_region', ['west'])->orIf('is_global')->for('pay_periods'))
```

`Build::check('is_open')` starts the predicate, and the rest of it is built on the
check like any condition. The predicate is read against the target, so a `can(...)`
inside it names one of the target's abilities.

There is no string form, because a bare condition name has no schema to be checked
against. A check needs `for()`, and a predicate of at least one term: unlike a
group it cannot fall back to `false`, because a predicate may not contain a
constant. Either mistake throws a `LogicException` where the check is written.

### Things to remember

**Negate through `ifNot`.** A reference is a condition, so there is no `ifNotCan`:

```php
->ifNot(Build::can('manage')->for('departments', Ref::context('department_id')))
->orIfNot(Build::check('is_closed')->for('pay_periods', Ref::column('pay_period_id')))
```

**Omitting the key means unbound.** Its default is a `NoRow` sentinel, not `null`:

```php
Build::can('access')->for('billing')                 // can(access for billing)
Build::can('view')->for('folders', $folder->id)      // can(view for folders('f-1'))
Build::can('view')->for('folders', null)             // row-bound, and rejected by validation
```

An explicit `null` stays row-bound, so a missing id fails loudly instead of quietly
widening the question. When composing dynamically and wanting the unbound form as a
fallback, say so: `->for('folders', $id ?? new NoRow)`.

**A multi-part key takes a list**, bound positionally to `matchKey()`:

```php
Build::can('assign')->for('shift_days', [Ref::column('team_id'), Ref::column('starts_on')])
```

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

A rule with neither `theyCan` nor `theyCannot` throws a `LogicException` when it is
finished, exactly as the language rejects a bare `if` with no clause.

With no abilities, a clause is generic: `->theyCan()`, `->theyCannot()` or
`->theyCannotBecause(message: '...')`. That is how a
[rule template's](/rules/templates/) body writes them, since the `@include`
expanding the template names the abilities.

`theyCannotBecause()` always needs its message. Called with abilities and no
message, as in `->theyCannotBecause('delete')`, it throws a `LogicException`
rather than reading `delete` as the message.

## A whole rule set

`Build::ruleSet()` hands its callback a `$rule`. Each `$rule()` starts a rule and
`$rule->include()` an `@include`; they keep the order you wrote them in, and
nothing calls `toRule()`:

```php
use Warrant\Builders\Build;
use Warrant\Builders\RuleSetBuilder;

Build::ruleSet('documents', function (RuleSetBuilder $rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');

    $rule()->if('is_locked')
        ->theyCannotBecause('update', 'This document is locked.');

    $rule->include('requires_approval', ['manager'])->for('publish');
    $rule->include('audit_trail')->for('*');
});
```

This is the shape you will most often return from `rules()` or a
[provider](/supplying-rules/provider/).

Given only the callback, `Build::ruleSet()` answers with the entries themselves,
which is what a rule template returns:

```php
#[RuleTemplate]
public function requiresApproval(string $level): array
{
    return Build::ruleSet(function (RuleSetBuilder $rule) use ($level) {
        $rule()->if('is_approved_by', [$level])->theyCan();
        $rule->include('audit_trail');      // no for(): the include is generic
    });
}
```

## Finishing a builder yourself

Warrant finishes every builder it is handed, so you only need these when your own
code works on the tree:

```php
$rule->toRule();                               // a WarrantRuleNode
$condition->buildConditions(new SyntaxSource(null));   // an expression, or null with no terms
Build::can('view')->toNode(new SyntaxSource(null));    // a CrossSchemaCanNode
```

## Why the compiler checks the builder too

A rule built this way never passes through the parser, so no pass over rule text
can see it. That is why the compiler carries its own copy of every check the
validator makes, rather than trusting that validation already ran. See
[the validator is never stricter than the
compiler](/sql/validator-compiler-invariant/).
