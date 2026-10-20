---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Rules
description: Examples of the rule language, from a single grant to references across schemas.
sidebar:
  order: 2
---

A rule says which abilities a condition grants or denies. These examples are
written in the rule language; the [next page](/usage-examples/builder/) builds the
same things in PHP. [Writing rules](/rules/basics/) covers the language in depth.

## Grants and denials

```warrant
if is_mine
they can view, update
they cannot delete
```

A rule with no `if` always applies:

```warrant
they can view
```

## Several rules

Each `if` starts a new rule. A matching denial beats any grant for the same
ability, and the order rules are written in never matters:

```warrant
they can view

if is_mine or in_my_team
they can update

if is_locked and not is_admin
they cannot update because 'This document is locked.'

if is_admin
they can *
```

## Combining conditions

`not` binds tighter than `and`, and `and` tighter than `or`:

```warrant
if is_mine or is_manager they can view
if is_mine and not is_locked they can update
if is_manager and (in_team('sales') or in_team('eng')) they can approve
if not (is_archived or is_locked) they can edit
```

## Wildcards

```warrant
if is_admin     they can *
if is_suspended they cannot *
```

## Denial messages

The message is what `authorize()` and `Gate::authorize()` report when this clause
is the cause of a denial:

```warrant
if is_locked
they cannot update because 'This document is locked and can no longer be edited.'
they cannot delete because 'Locked documents cannot be deleted.'
```

See [Denial messages](/rules/denial-messages/).

## Arguments and values

```warrant
if in_team('sales') they can view
if seen_recently(30, true) they can view
if has_rating(4.5) they can promote
if assigned_to(null) they can claim
```

Bindings keep runtime values out of the text:

```php
use Warrant\Facades\Warrant;

Warrant::parse(warrant('if in_team(:team) they can view', ['team' => $team]))->validate()->rule();
Warrant::parse(warrant('if in_team(?, ?) they can view', ['sales', 'eng']))->validate()->rule();
```

See [Values](/rules/values/).

## Context and columns as arguments

`@context` passes a value from the check, and `@column` a column of the row:

```warrant
if in_workspace(@context workspace_id) they can view, edit
if pay_period_matches(@column pay_period_id) they can view
```

```php
Warrant::can('view', $document, ['workspace_id' => 'ws-1']);
```

## Delegating to another ability

`can(...)` with no schema asks about another ability on the same row:

```warrant
if is_owner or is_editor they can edit
if can(edit) they can comment
if can(comment) they can share
```

## Delegating to another schema

`can(...)` asks whether the user holds an ability on another schema's row:

```warrant
if can(view for folders(@column folder_id)) they can view
if can(edit for folders(@column folder_id)) they can edit, delete
```

Pass context across the boundary with `with`:

```warrant
if can(edit for projects(@column project_id) with tenant_id = @context tenant_id)
they can edit
```

A handle with no row asks about the schema as a whole:

```warrant
if can(manage for settings) they can view
```

## Asking about another schema's state

`check(...)` asks one of another schema's conditions, instead of its rules:

```warrant
if is_author and check(is_open for pay_periods(@column pay_period_id))
they can submit

if check((is_open or is_grace_period) and not is_locked for pay_periods(@column pay_period_id))
they can submit
```

## Naming the rows with `as`

When a reference reaches a table that is already in scope, name it:

```warrant
if can(view for folders(@column parent_id) as parent) they can view
```

See [References](/rules/references/) and [Across schemas](/rules/cross-schema/).

## Ability blocks

Say the abilities once, over a body of rules that name none:

```warrant
can they edit, delete {
    if is_owner they can
    if is_archived they cannot because 'This document is archived.'
}
```

See [Ability blocks](/rules/ability-blocks/).

## Templates

Expand a template the schema declares:

```warrant
if is_owner they can view
@include requires_approval for view, edit
@include inherited_from(@column parent_id) for view
```

See [Rule templates](/rules/templates/).

## Rule sets for several schemas

A `.warrant` file, or any rule text, can hold rule sets for several schemas, each
braced:

```warrant
for documents {
    if is_mine or in_my_team they can view, update
    if is_locked they cannot update because 'This document is locked.'
}

for folders {
    if is_member they can view
}
```

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

$file = WarrantSyntax::parseFile(base_path('warrant/editor.warrant'))->validate();

$file->forSchema('documents');   // the RuleSetNode, or null
$file->schemaKeys();             // ['documents', 'folders']
```
