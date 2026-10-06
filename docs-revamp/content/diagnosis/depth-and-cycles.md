---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Depth and cycle errors
description: Why a hop chain was refused, and how to restructure it.
sidebar:
  order: 4
---

Two related errors: a cycle, and a chain that nests too deep.

## A cycle

```text
Cross-schema can(...) cycle detected: timesheets:create → pay_periods:approve →
timesheets:create. A can(...) reference must not, directly or transitively, depend
on the ability being compiled.
```

Because `can(...)` compiles the other schema's rules, and those rules may reference
a third, a chain can close on itself. The compiler tracks the `(schema, ability)`
frames on the current path and refuses a repeat.

The guard is path-scoped. Two sibling references to the same schema are fine; only
re-entering a frame already on the path is a cycle.

### Reading the chain

The message is the path. Read it as "to answer this, I had to answer that, which
needed this again". The fix is almost always to break the dependency at the
weakest link.

### The self-reference case

The most common cycle is a rule that recurses into its own ability:

```warrant
for folders {
    if is_owner they can view
    if can(view for folders(@column parent_id)) they can view   # folders:view again
}
```

Inheritance down a tree needs a shape the database can answer in one step. A
closure table:

```php
#[RowCondition]
public function grantedAtOrAbove(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('folder_paths')
        ->join('folder_grants', 'folder_grants.folder_id', '=', 'folder_paths.ancestor_id')
        ->whereColumn('folder_paths.descendant_id', $c->row('id'))
        ->where('folder_grants.user_id', $c->user->getAuthIdentifier()));
}
```

```warrant
for folders {
    if granted_at_or_above they can view
}
```

One subquery, whatever the depth. See [hierarchies](/recipes/hierarchies/).

### The mutual-dependency case

```warrant
for documents  { if can(approve for timesheets(@column timesheet_id)) they can view }
for timesheets { if can(view for documents(@column document_id)) they can approve }
```

Two schemas each deferring to the other. Pick one direction and make the other a
`check(...)`, which asks about a row's state and consults no rules, so it cannot
close a loop:

```warrant
for timesheets { if check(is_published for documents(@column document_id)) they can approve }
```

### Delegation within one schema

```warrant
if can(read) they can comment
if can(comment) they can read      # documents:read → documents:comment → documents:read
```

When two abilities genuinely share a condition rather than one depending on the
other, name the condition instead:

```php
#[DerivedCondition]
public function isContributor(): string
{
    return 'is_owner or is_editor';
}
```

```warrant
if is_contributor they can read, comment
```

That composes with no dependency between abilities. See
[delegation](/rules/delegation/).

## Depth

```text
Warrant compilation exceeded the maximum nesting depth of 64.
Expansion exceeded the maximum nesting depth of 64.
```

There are two budgets of 64, one per phase:

- **Compiling** counts `can(...)` hops and `check(...)` dispatches.
- **Expansion**, which runs once per rule set before anything compiles, counts
  [rule template](/rules/templates/) includes and
  [derived condition](/schemas/conditions-beyond-sql/) expansions, on one shared
  budget.

Hitting either by accident is rare. For expansion the cause is usually one of two
things.

**A template with no base case.** The language has no conditional, so a body cannot
decide for itself when to stop. The base case has to be a PHP one:

```php
#[RuleTemplate]
public function ancestor(int $depth): string|WarrantSyntax
{
    return $depth <= 0
        ? 'they can'
        : Warrant::parse('@include ancestor(:next)', ['next' => $depth - 1]);
}
```

Recursion is bounded by depth rather than by rejecting a repeated name, because a
template recurring with a decreasing argument terminates, and rejecting on the name
would ban exactly those.

**A derived condition expanding into itself:**

```php
#[DerivedCondition]
public function runaway(): WarrantConditionBuilder
{
    return WarrantConditionBuilder::build()->if('runaway');
}
```

Each error names the chain that got there, so the offending name is in the
message. An expansion chain collapses repeats:

```text
Expansion exceeded the maximum nesting depth of 64.

Expansion chain (outermost first):
  documents.runaway  (repeated 65 times)
```

A compile chain names the abilities and hops instead.

## Before you raise the depth

You cannot, and that is deliberate. A predicate 64 subqueries deep would be
unreadable and very likely unindexable. Treat the error as a signal to flatten the
relationship into something the database can answer in one step, which is nearly
always a closure table, a denormalized column, or a materialized path.
