---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: When blocks
description: Say a condition once when several rules depend on it.
sidebar:
  order: 3
---

When several rules only apply in one situation, repeating that condition on every
rule buries what is actually different between them. A when block says it once:

```warrant
when is_published {
    if is_member they can view
    if is_owner they can edit
} otherwise {
    they cannot view because 'This document is not published.'
}
```

`when` sets the situation, and the `if` rules inside it say what holds there.
That decides every check exactly as writing each rule in full does:

```warrant
if is_published and is_member they can view
if is_published and is_owner they can edit
if not is_published they cannot view because 'This document is not published.'
```

Every rule inside the block takes the block's condition, joined with `and` to its
own. A rule with no `if` of its own takes the condition alone. Every rule inside
`otherwise` takes `not` the condition instead.

## Otherwise

`otherwise` is optional, and always followed by a body in braces. There is no
`otherwise when`. A branch that means "not the condition before, and this one" is
the kind of logic a reader has to work out every time they meet it, so Warrant
leaves it to be written out:

```warrant
when is_published {
    if is_member they can view
}

when is_draft {
    if is_owner they can view
}
```

When the cases depend on each other, say so in the condition:
`when not is_published and is_draft`.

## When the condition has no answer

A condition can be [unknown](/concepts/three-truth-values/): a `NULL` column, or a
row condition asked with no row. `otherwise` is a plain `not`, and `not` of an
unknown is unknown, so **when the condition has no answer, neither body has any
effect**. Nothing in either body grants, and a `they cannot` in either body still
blocks.

That is the safe direction. If `otherwise` covered the unknown case, this would
grant `view` to a member whenever `is_suspended` could not be answered:

```warrant
when is_suspended {
    they cannot view
} otherwise {
    if is_member they can view
}
```

A condition over a column that may be `NULL` can decide what `NULL` means itself.
See [true, false, and unknown](/concepts/three-truth-values/).

## Nesting, ability blocks and templates

When blocks nest, and their conditions stack:

```warrant
when is_published {
    when is_archived {
        they cannot edit because 'Archived documents are read-only.'
    } otherwise {
        if is_owner they can edit
    }
}
```

Each `otherwise` belongs to the `when` directly above it. Past one level that
takes reading the braces, so two blocks that each say their condition are often
clearer than a nested `otherwise`.

A when block may sit inside an [ability block](/rules/ability-blocks/), and an
ability block inside a when block. The rules inside take their abilities from the
ability block's header either way:

```warrant
can they view {
    when is_published {
        if is_public they can
    } otherwise {
        if is_owner they can
    }
}
```

An [`@include`](/rules/templates/) inside a when block expands first, and every
rule it expands to takes the condition. A template's body may hold a when block
too.

## What is rejected

```warrant
when is_published if is_member they can view   # braces are always required
when is_published { }                          # an empty body
when a { they can view } otherwise when b { }  # otherwise takes a body, not another when
for docs { when a { if b they can } }          # a generic can/cannot clause in a rule set
```

A one-rule block needs its braces too. It can always be written as one rule with
`and` instead.

The rules inside a block follow whatever the rules around it follow: they name
their abilities in a rule set, and leave them to the header in an ability block.

:::note[When blocks in the parsed tree]
A when block stays in the parsed tree as a `WhenBlockNode` holding its condition
and both bodies as written, and [`toSyntax()`](/reference/warrant-rule-set/)
writes it back as a block. [Expansion](/sql/rule-to-query/#before-compiling-expansion)
puts the condition on each rule, so nothing after it knows the block was there.
The condition is expanded once and the same node goes on every rule of the block.
:::
