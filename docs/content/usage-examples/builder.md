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
covers it in depth. Return a builder from `rules()`, a provider, a template or a
derived condition as it is: Warrant finishes it.

## A rule

```php
use Warrant\Builders\Build;

Build::rule()
    ->if('is_mine')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->theyCannot('delete');
```

That is this rule:

```warrant
if is_mine or (is_manager and in_region)
they can view, update
they cannot delete
```

## Connectives

```php
Build::rule()
    ->if('is_mine')                  // the first term
    ->andIf('in_team', ['sales'])    // and in_team('sales')
    ->orIf('is_admin')               // or is_admin
    ->ifNot('is_archived')           // and not is_archived
    ->orIfNot('is_locked')           // or not is_locked
    ->theyCan('view');
```

Precedence matches the language: `->if('a')->andIf('b')->orIf('c')` is
`(a and b) or c`. A closure is a parenthesized group.

## A flag, folded in

A bool is a constant in the tree:

```php
Build::rule()
    ->if(config('documents.self_publish'))
    ->andIf('is_mine')
    ->theyCan('publish');
```

## A condition, reused

```php
$visible = Build::condition()->if('is_public')->orIf('is_mine');

return [
    Build::rule()->if($visible)->andIfNot('is_archived')->theyCan('view'),
    Build::rule()->ifNot($visible)->theyCannotBecause('view', 'You cannot see this document.'),
];
```

## Denial messages

```php
Build::rule()
    ->if('is_locked')
    ->theyCannotBecause('update', 'This document is locked and can no longer be edited.')
    ->theyCannotBecause(['delete', 'archive'], 'Locked documents cannot be changed.');
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
Build::rule()
    ->if('is_mine')
    ->orIf(function ($c) use ($teamIds) {
        foreach ($teamIds as $id) {
            $c->orIf('in_team', [$id]);
        }
    })
    ->theyCan('view');
```

## Runtime branches

```php
Build::rule()
    ->if('is_mine')
    ->when($includeManagers, fn ($r) => $r->orIf('is_manager'))
    ->theyCan('view');

Build::rule()
    ->if('is_manager')
    ->when($isSenior,
        fn ($r) => $r->theyCan('view', 'approve'),
        fn ($r) => $r->theyCan('view'))
    ->unless($readOnly, fn ($r) => $r->theyCan('update'));
```

## Values the language cannot write inline

Parameters are passed through untouched, so any PHP value works:

```php
Build::rule()
    ->if('within_polygon', [$geoJsonArray])
    ->theyCan('view');
```

## Context, columns, and SQL

```php
use Warrant\Builders\Ref;

Build::rule()
    ->if('in_workspace', [Ref::context('workspace_id')])
    ->andIf('pay_period_matches', [Ref::column('pay_period_id')])
    ->theyCan('view');
```

`Ref::sql('select …')` passes a raw SQL fragment, emitted as written.

## Splicing in rule text

```php
Build::rule()
    ->ifRaw('is_admin or is_owner')
    ->andIfRaw('in_region(:region)', ['region' => 'west'])
    ->andIfNotRaw('is_archived or is_locked')
    ->theyCan('update');
```

`ifRaw`, `andIfRaw`, `orIfRaw`, `ifNotRaw`, `andIfNotRaw` and `orIfNotRaw` each
splice their text in as one group.

## Another schema's abilities

```php
use App\Models\PayPeriod;

Build::rule()
    ->if('is_author')
    ->orIfCan(Build::can('approve')->for(PayPeriod::class, Ref::context('period_id')))
    ->theyCan('submit');

Build::rule()->ifCan(Build::can('access')->for('billing'))->theyCan('export');            // no row
Build::rule()->ifCan(Build::can('view')->for('folders', $folder->id))->theyCan('attach');
Build::rule()->ifCan('edit')->theyCan('comment');                                          // same schema
```

The closure form is handed a factory that starts from the ability:

```php
->ifCan(fn ($can) => $can('manage')
    ->for(Department::class, Ref::column('department_id'))
    ->as('dept')
    ->with(['fiscal_year' => Ref::context('year')]))
```

A key of several parts takes a list:

```php
Build::can('assign')->for('shift_days', [Ref::column('team_id'), Ref::column('starts_on')])
```

## Another schema's state

```php
Build::rule()
    ->if('is_author')
    ->andIfCheck(Build::check('is_open')->for('pay_periods', Ref::column('pay_period_id')))
    ->theyCan('submit');

Build::rule()
    ->if('is_author')
    ->andIfCheck(fn ($check) => $check
        ->if('is_open')
        ->andIfNot('is_locked')
        ->for('pay_periods', Ref::column('pay_period_id')))
    ->theyCan('submit');
```

## Negating a reference

There is no `ifNotCan`; pass the reference to `ifNot`:

```php
->ifNot(Build::can('manage')->for('departments', Ref::context('department_id')))
->orIfNot(Build::check('is_closed')->for('pay_periods', Ref::column('pay_period_id')))
```

## A condition on its own

```php
Build::condition()->if('is_owner')->orIf('is_admin');
```

This, a `Build::can(...)` or a `Build::check(...)` is what a derived condition can
answer with. See [Schemas](/usage-examples/schemas/#derived-conditions).

## A rule set

Each `$rule()` starts a new rule and each `$rule->include()` an `@include`; there
is no `toRule()` to call:

```php
Build::ruleSet('documents', function ($rule) {
    $rule()->if('is_mine')->theyCan('view', 'update');
    $rule()->if('is_locked')->theyCannotBecause('update', 'This document is locked.');
    $rule()->if('is_admin')->theyCan('*');
    $rule->include('requires_approval', ['manager'])->for('publish');
});
```

Rules you already have need no set around them: return them as a list from
`rules()`:

```php
return [$own, $noDelete];
```

## A template's body

Given only a callback, `Build::ruleSet()` answers with the entries themselves.
Clauses and includes with no abilities are generic, as a template writes them:

```php
#[RuleTemplate]
public function requiresApproval(string $level): array
{
    return Build::ruleSet(function ($rule) use ($level) {
        $rule()->if('is_approved_by', [$level])->theyCan();
        $rule()->if('is_draft')->theyCannotBecause([], 'This document has not been approved yet.');
        $rule->include('audit_trail');
    });
}

#[RuleTemplate]
public function auditTrail(): WarrantRuleBuilder
{
    return Build::rule()->if('has_audit_entry')->theyCan();
}
```

## Mixing text and builders

```php
$dynamic = Build::ruleSet('documents', fn ($rule) => $rule()
    ->if('in_team', [$teamId])
    ->theyCan('view'));

return [$textFromDatabase, $dynamic, Build::rule()->if('is_admin')->theyCan('*')];
```
