---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Delegation
description: Answer one ability in terms of another, on this schema or another one.
sidebar:
  order: 7
---

Policies are full of sentences like "anyone who can edit can also comment" and
"whoever may edit the project may edit its tasks". Writing those out restates the
same condition in two places, and the two drift.

Delegation says them once.

## Within one schema

`can(<ability>)` with no `for` clause asks about another ability of this schema,
over the row this rule is already about:

```warrant
for documents {
    if is_owner or is_editor they can edit
    if can(edit) they can comment
    if can(comment) they can share
}
```

Change who may edit and the other two follow.

Nothing is crossed, so there is no boundary to declare: the check-time context
comes along unchanged, and the form takes no `with` map and no `as`. It emits no
subquery either. The named ability's predicate compiles straight into the frame the
reference sits in, so the whole chain above collapses to a single
`documents.owner_id = ? or documents.editor_id = ?`.

## Across schemas

With a `for` clause, the question goes to another schema's rules:

```warrant
for tasks {
    if can(edit for projects(@column project_id)) they can edit
}
```

Whatever the project policy says about editing now governs its tasks. A project
rule added tomorrow reaches tasks with no change here.

Remember that a `for` crosses a boundary, and a boundary resets the context:

```warrant
if can(edit for projects(@column project_id) with tenant_id = @context tenant_id)
they can edit
```

See [context across boundaries](/concepts/context/across-boundaries/).

## Delegating downward

The reverse direction is just as common. A folder's rules decide, and every
document in it follows:

```warrant
for documents {
    if can(view for folders(@column folder_id)) they can view
    if can(edit for folders(@column folder_id)) they can edit, delete
}
```

Note that the abilities need not match. `edit` on a folder grants both `edit` and
`delete` on its documents, which is a policy decision expressed in one line.

## Delegating state rather than permission

When the sentence is about a row's condition rather than about permission,
`check(...)` is the right builtin, and it is cheaper because it consults no rules:

```warrant
for timesheets {
    if is_author and check(is_open for pay_periods(@column pay_period_id))
    they can submit
}
```

## When not to delegate

Delegation makes one ability depend on another, and a dependency can cycle. If
`comment` delegates to `edit` and `edit` delegates to `comment`, the compiler
refuses:

```text
Cross-schema can(...) cycle detected: documents:comment → documents:edit →
documents:comment.
```

When two abilities genuinely share a condition rather than one depending on the
other, name the condition instead:

```php
#[RowCondition]
public function isContributor(RowConditionContext $c)
{
    return Warrant::condition('is_owner or is_editor');
}
```

```warrant
if is_contributor they can edit, comment
```

That is a [derived condition](/schemas/conditions-beyond-sql/), and it composes
without creating a dependency between abilities.
