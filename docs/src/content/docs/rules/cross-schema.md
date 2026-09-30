---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Longer chains
description: Depth, cycles, aliasing, and what a hop actually costs.
sidebar:
  order: 6
---

One hop is easy to reason about. Chains get interesting, and this page is the
constraints you meet as they grow.

A file inherits access from its folder, which inherits from its parent folder,
which belongs to a team:

```warrant
for files {
    if can(view for folders(@column folder_id)) they can view
}

for folders {
    if is_owner they can view
    if can(view for folders(@column parent_id) as parent) they can view
}
```

That second rule is recursive in shape, and it terminates because a row with a null
`parent_id` selects nothing.

## Cycles

Because `can(...)` compiles the other schema's rules, a chain can close on itself.
The compiler tracks the `(schema, ability)` frames on the current path and refuses
a repeat:

```text
Cross-schema can(...) cycle detected: timesheets:create → pay_periods:approve →
timesheets:create. A can(...) reference must not, directly or transitively, depend
on the ability being compiled.
```

The guard is path-scoped, so two sibling references to the same schema are fine.
Only re-entering a frame already on the path is a cycle.

Note what that means for the recursive folder rule above: `folders:view` referring
to `folders:view` is the same frame, so it is caught. Self-reference through the
same ability needs a different shape, usually a closure table or a materialized
path read by a condition:

```php
#[RowCondition]
public function inAncestorOfMine(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('folder_paths')
        ->whereColumn('folder_paths.descendant_id', $c->row('id'))
        ->join('folder_owners', 'folder_owners.folder_id', '=', 'folder_paths.ancestor_id')
        ->where('folder_owners.user_id', $c->user->getKey()));
}
```

A `check(...)` dispatch touches no rules of its own, so the dispatch cannot close a
loop. A `can(...)` inside its predicate can, and is guarded the same way.

## Depth

Nesting is capped at 32, counting `can(...)` hops, `check(...)` dispatches,
[template](/rules/templates/) expansions, and derived conditions together. The
error names the chain that got there.

## Same connection only

The target schema has to be on the same database connection as the query being
filtered. A subquery cannot reach across connections.

## Aliasing across a chain

Every frame in a chain gets its own identifier, and this is where
[`warrantQualifyColumn()`](/concepts/frames/) stops being academic. A condition on
`folders` that wrote `folders.owner_id` produces SQL naming `folders` inside a
subquery where the rows are called `parent`. Use `$c->row()` in conditions and
`warrantQualifyColumn()` in scopes and the chain works at any depth.

## What it compiles to

A row-bound reference becomes an `EXISTS` over the target's table, keyed to the
selector, with the target's compiled predicate inside:

```warrant
if can(view for folders(@context folder_id)) they can view
```

```sql
select * from "documents" where (
    exists (
        select * from "folders"
        where "folders"."id" = 'f-1'
          and ("folders"."owner" = 'role-1')
    )
)
```

Under negation the same subquery is emitted as `not exists (...)`. Two references
on sibling branches produce two independent `EXISTS` clauses.

An unbound reference has nothing to correlate, so the target's boolean tree is
spliced inline rather than wrapped in a subquery, which lets a target that decides
outright fold into the caller's predicate instead of stopping at a literal.

## Handing over the row itself

When the row selector is a hydrated model rather than a key, the `EXISTS` can
disappear. The subquery only ever asked two things, whether the row exists and
whether the other schema allows it, and a hydrated model has already settled the
first:

```warrant
if can(view for folders(@context folder)) they can view
```

```php
$user->warrant()->can('view', $document, ['folder' => $folder]);
```

The folder schema's row conditions receive it as `$c->model` and may answer in PHP.
If the whole of that schema folds to a constant, the reference becomes that
constant and no subquery is built. A key alone cannot do this, because whether the
row exists is exactly what a key has not established.

## Restrictions in one place

Caught when the rule set is validated or compiled:

- The target schema must be registered, and for `can(...)` must declare the
  ability. A reference may target its own schema.
- A row-bound handle needs a target with rows.
- A row selector resolving to a literal `null` is rejected. One that resolves to
  nothing at check time, such as an absent `@context`, is unanswerable rather than
  rejected.
- `as <alias>` needs a row to name.
- A `check(...)` predicate is read against the target's vocabulary. It may not hold
  a constant, and on an unbound handle it may not hold a row condition.
- `can(<ability>)` with no `for` takes no `with` map and no `as`.
- A `@column` reference must name a frame in scope where it is written.

Caught at compile time:

- A row selector that is a model of the wrong schema, or any object with no meaning
  as a row key.
- A target on a different connection.
- A cycle, or nesting deeper than 32.
