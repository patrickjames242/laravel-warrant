---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rules in PHP
description: Examples of building rules, conditions, and rule sets with the fluent builder.
sidebar:
  order: 3
---

The builder makes the same rules as the language, as PHP objects. Reach for it
when a rule's shape depends on runtime data. [The PHP builder](/rules/builder/)
covers it in depth.

## A rule

```php
use Warrant\Facades\Warrant;

Warrant::rule()
    ->if('is_mine')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->theyCannot('delete')
    ->toRule();
```

That is this rule:

```warrant
if is_mine or (is_manager and in_region)
they can view, update
they cannot delete
```

`Warrant::rule()` and `WarrantRuleNode::build()` are the same call.

## Connectives

```php
Warrant::rule()
    ->if('is_mine')                  // the first term
    ->andIf('in_team', ['sales'])    // and in_team('sales')
    ->orIf('is_admin')               // or is_admin
    ->ifNot('is_archived')           // and not is_archived
    ->orIfNot('is_locked')           // or not is_locked
    ->theyCan('view')
    ->toRule();
```

Precedence matches the language: `->if('a')->andIf('b')->orIf('c')` is
`(a and b) or c`. A closure is a parenthesized group.

## Denial messages

```php
Warrant::rule()
    ->if('is_locked')
    ->theyCannotBecause('update', 'This document is locked and can no longer be edited.')
    ->theyCannotBecause(['delete', 'archive'], 'Locked documents cannot be changed.')
    ->toRule();
```

A message can be a closure that sees the row, and may return an exception to throw:

```php
use Warrant\Schema\WarrantDenialContext;

->theyCannotBecause('update', fn (WarrantDenialContext $c) =>
    "You cannot edit {$c->target->title} while it is locked.")
```

## Folding a list

An empty group is `false`, so folding an empty list adds nothing to an `or`:

```php
Warrant::rule()
    ->if('is_mine')
    ->orIf(function ($c) use ($teamIds) {
        foreach ($teamIds as $id) {
            $c->orIf('in_team', [$id]);
        }
    })
    ->theyCan('view')
    ->toRule();
```

## Runtime branches

```php
Warrant::rule()
    ->if('is_mine')
    ->when($includeManagers, fn ($r) => $r->orIf('is_manager'))
    ->theyCan('view')
    ->toRule();
```

## Values the language cannot write inline

Parameters are passed through untouched, so any PHP value works:

```php
Warrant::rule()
    ->if('within_polygon', [$geoJsonArray])
    ->theyCan('view')
    ->toRule();
```

## Context, columns, and SQL

```php
use Warrant\Builders\Ref;

Warrant::rule()
    ->if('in_workspace', [Ref::context('workspace_id')])
    ->andIf('pay_period_matches', [Ref::column('pay_period_id')])
    ->theyCan('view')
    ->toRule();
```

`Ref::sql('select …')` passes a raw SQL fragment, emitted as written.

## Splicing in rule text

```php
Warrant::rule()
    ->ifRaw('is_admin or is_owner')
    ->andIf('in_region')
    ->theyCan('update')
    ->toRule();
```

## Another schema's abilities

```php
use App\Models\PayPeriod;

Warrant::rule()
    ->if('is_author')
    ->orIfCan('approve', PayPeriod::class, Ref::context('period_id'))
    ->theyCan('submit')
    ->toRule();

Warrant::rule()->ifCan('access', 'billing')->theyCan('export')->toRule();      // no row
Warrant::rule()->ifCan('view', 'folders', $folder->id)->theyCan('attach')->toRule();
Warrant::rule()->ifCan('edit')->theyCan('comment')->toRule();                  // same schema
```

A key of several parts takes a list:

```php
->ifCan('assign', 'shift_days', [Ref::column('team_id'), Ref::column('starts_on')])
```

## Another schema's state

```php
Warrant::rule()
    ->if('is_author')
    ->andIfCheck(
        fn ($p) => $p->if('is_open')->andIfNot('is_locked'),
        'pay_periods',
        Ref::column('pay_period_id'),
    )
    ->theyCan('submit')
    ->toRule();

->ifCheck('is_open', 'pay_periods', Ref::context('period_id'))
```

There are no negated forms. Negate with a group:

```php
->ifNot(fn ($c) => $c->ifCan('manage', 'departments', Ref::context('department_id')))
```

## A condition on its own

```php
Warrant::condition()->if('is_owner')->orIf('is_admin');
```

This is what a derived condition can answer with. See
[Schemas](/usage-examples/schemas/#derived-conditions).

## A rule set

Each `$rule()` starts a new rule; there is no `toRule()` to call:

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;

RuleSetNode::build('documents', function ($rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');
    $rule()->if('is_locked')->theyCannotBecause('update', 'This document is locked.');
    $rule()->if('is_admin')->theyCan('*');
});
```

Or from rules you already have:

```php
RuleSetNode::fromRules('documents', [$own, $noDelete]);
```

## Mixing text and builders

```php
$stored  = Warrant::parse($textFromDatabase)->scopedTo('documents');
$dynamic = RuleSetNode::build('documents', fn ($rule) => $rule()
    ->if('in_team', [$teamId])
    ->theyCan('view'));

$stored->mergeWith($dynamic);
```
