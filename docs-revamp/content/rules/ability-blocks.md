---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Ability blocks
description: Say the ability once when several rules are about it.
sidebar:
  order: 2
---

When several rules concern one ability, repeating its name on every clause buries
what is actually different between them. An ability block says it once in the
header:

```warrant
can they view {
    if is_public they can
    if is_locked they cannot because 'This document is locked.'
}
```

That is exactly the same as writing each clause in full:

```warrant
if is_public they can view
if is_locked they cannot view because 'This document is locked.'
```

Clauses inside a block name no abilities, because the header already did.

## Several abilities, and wildcards

A header may list several, or use `*`:

```warrant
can they edit, delete {
    if is_owner they can
    if is_archived they cannot because 'This document is archived.'
}

can they * {
    if is_suspended they cannot because 'Your account is suspended.'
}
```

## Mixing with ordinary rules

Blocks and plain rules coexist in any order:

```warrant
for documents {
    if is_admin they can *

    can they view {
        if is_public they can
        if is_confidential and not has_clearance they cannot
    }

    if is_archived they cannot edit because 'This document is archived.'

    can they edit, delete {
        if is_owner they can
        if is_locked they cannot because 'This document is locked.'
    }
}
```

The same ability may appear in more than one header, and nothing is lost when it
does. Both blocks' rules apply, exactly as the longhand would.

## What is rejected

Three things, each because the header is the one place the ability is said:

```warrant
can they view { if x they can edit }   # a clause naming its own abilities
can they view { can they edit { … } }  # a block inside a block
if is_public they can                  # a headless clause at the top level
```

## Where templates fit

A block is the most natural home for an [`@include`](/rules/templates/). The header
names the abilities, so the include needs no `for` list of its own:

```warrant
can they view, edit {
    @include requires_approval
}
```

Outside a block, the include has to name them:

```warrant
@include requires_approval for view, edit
```

:::note[Blocks do not survive parsing]
A block is expanded into ordinary rules as it is read, so
[`toSyntax()`](/reference/warrant-rule-set/) renders the longhand rather than
reconstructing the block. Since [rule order never
matters](/concepts/grants-and-denials/), nothing downstream can tell the two forms
apart.
:::
