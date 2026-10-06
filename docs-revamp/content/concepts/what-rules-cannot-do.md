---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: What rules cannot do
description: The limits, why each one exists, and the escape hatch for each.
sidebar:
  order: 9
---

Worth knowing early. Every limit here follows from
[rules compiling to SQL](/concepts/rules-compile-to-sql/), and every one has a way
out.

## A rule cannot call arbitrary PHP

There is no `if (SomeService::check($document))` in the rule language. When a
filter query runs, your PHP is not there. The database is.

**The way out** is a condition. A condition is arbitrary PHP, and it runs at
compile time. What it has to produce is a query constraint, a `bool`, or `null`:

```php
#[GlobalCondition]
public function hasActiveSubscription(GlobalConditionContext $c): bool
{
    return app(Billing::class)->isActive($c->user);   // any PHP you like
}
```

Called once per compile, not once per row.

## A condition cannot join, group, or aggregate

A condition must produce something the compiler can `AND`, `OR`, and negate in
place. A `where` is that. A `join` or `groupBy` changes the query's row shape, so
emitting one throws:

```text
Condition [manages_team] on schema [documents] may only add where clauses, but it
emitted a [join]; ...
```

**The way out** is a correlated `whereExists`, which stays a boolean and never
multiplies rows:

```php
#[RowCondition]
public function managesTeam(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('team_managers')
        ->whereColumn('team_managers.team_id', $c->row('team_id'))
        ->where('user_id', $c->user->getKey()));
}
```

## A condition cannot return the query untouched

Adding nothing would silently mean "match every row", which is almost always a
forgotten branch:

```text
Condition [x] on schema [Y] added no where clause; a condition must add at least
one where clause, return true/false to decide the outcome outright, or return null
to answer unknown.
```

**The way out** is to say it on purpose: return `true`.

## A `cannot` cannot be appealed

Once a `cannot` matches, no `can` anywhere brings the ability back. There is no
"deny here, but an outer rule may re-grant".

**The way out** today is to write the exception into the denial itself, with
`and not`:

```warrant
if is_locked and not is_admin they cannot update
```

That requires whoever owns the denial to anticipate the exception. Locally
overridable denials are [on the roadmap](/roadmap/planned/).

## A rule cannot see a fact the database does not hold

A rule can compare columns and bound values. It cannot ask about something that
exists only in the request.

**The way out** is [context](/concepts/context/), which is exactly the channel for
facts known only at check time:

```warrant
if in_workspace(@context workspace_id) they can view
```

## Context does not cross a schema boundary

A hop into another schema hands it a fresh, empty context bag. Nothing is
inherited:

```warrant
if can(view for folders(@context folder_id)) they can view
```

`folders` sees no context at all there.

**The way out** is the `with` map, which says explicitly what crosses:

```warrant
if can(view for folders(@context folder_id) with tenant_id = @context tenant_id)
they can view
```

## A hop chain cannot be unbounded

A `can(...)` compiles the other schema's rules, which may reference a third, which
may come back. The compiler tracks the `(schema, ability)` frames on the current
path and refuses a repeat:

```text
Cross-schema can(...) cycle detected: timesheets:create → pay_periods:approve →
timesheets:create.
```

Nesting is also capped at a depth of 64.

**The way out** is usually to restructure so the dependency runs one way, or to use
`check(...)`, which asks about a row's state and consults no rules.

## A message cannot be computed per check in rule text

`because '...'` is fixed when the rule is parsed, so a `@context` reference is not
accepted there.

**The way out** is a closure message, carried through a binding:

```php
WarrantSyntax::parse('if is_locked they cannot update because :msg', [
    'msg' => fn (WarrantDenialContext $c) => "You cannot edit {$c->target->title} while it is locked.",
])->rule();
```

## Only three database families

PostgreSQL, MySQL or MariaDB, and SQLite. The per-row ability column needs a
native JSON aggregate, and any other driver throws when the query is built.
[Database differences](/sql/databases/).
