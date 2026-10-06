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

That decides every check exactly as writing each clause in full does:

```warrant
if is_public they can view
if is_locked they cannot view because 'This document is locked.'
```

Clauses inside a block name no abilities, because the header already did. A rule
like that is **generic**: it can grant or deny whichever abilities it is given,
and the block gives it the header's. A rule whose clauses name their abilities is
**specific**, and a block is how generic rules become specific. A rule template's
body is generic in the same way, and its [`@include`](/rules/templates/) supplies
the abilities.

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
for docs { if is_public they can }     # a generic clause in a rule set
```

Rules with no `for` header may all leave their abilities off, so the same text can
be a [template's](/rules/templates/) body. Such text is rejected when it is placed
in a rule set, and text mixing the two, `if a they can view  if b they cannot`, is
a syntax error.

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

:::note[Blocks in the parsed tree]
A block stays in the parsed tree as an `AbilityBlockNode` holding its body exactly
as written, with clauses and includes that name no abilities. The header is the
only place the abilities live, so [`toSyntax()`](/reference/warrant-rule-set/)
writes the block back as a block. [Expansion](/sql/rule-to-query/#before-compiling-expansion)
applies the header to each entry, and since [rule order never
matters](/concepts/grants-and-denials/), every check decides the same way either
form is written.
:::
