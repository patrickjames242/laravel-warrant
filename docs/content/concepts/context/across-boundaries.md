---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Context across boundaries
description: A hop inherits nothing from its caller. The with map is what crosses.
sidebar:
  order: 6
---

A cross-schema reference hands the other schema a **fresh** context bag, holding
only that schema's own `defaultContext()`. The bag the check was made with belongs
to the schema being checked, and nothing of it leaks across.

```warrant
if can(view for folders(@context folder_id)) they can view
```

Inside `folders`, the context is whatever `folders` defaults. A rule of `folders`
reading `@context tenant_id` gets the default `folders` gives that key, or `null`
when it gives none.

That is deliberate. A rule set for `folders` is written by whoever owns `folders`,
and it should not silently depend on what some other schema happened to be checked
with.

## The `with` map

Say explicitly what crosses:

```warrant
if can(view for folders(@context folder_id) with tenant_id = @context tenant_id)
they can view
```

Each key names one of the target schema's context keys. Each value is resolved in
the *calling* schema's frame, so a literal, a binding, `@context`, `@column`, or
`@sql` all work:

```warrant
if can(view for folders(@column folder_id)
    with tenant_id = @context tenant_id,
         as_of     = '2026-01-01',
         owner_id  = @column owner_id)
they can view
```

Keys may be renamed on the way across. Here the caller's `region` becomes the
target's `scope`:

```warrant
if check(in_scope(@context scope) for folders(@context folder_id) with scope = @context region)
they can view
```

A key in the map overrides any default the target gives it. Duplicate keys in one
map are a syntax error.

## Required keys at the boundary

The bag is exactly the target's defaults and the map. A key the target requires,
by `#[RequiredContext]` or on the ability a `can(...)` names, must be in it, or the
rule throws when it compiles:

```text
Schema [App\Warrant\FolderSchema] requires context key(s) [tenant_id]; pass them in
the `with` map of the reference to [folders], or via its defaultContext().
```

That is always a mistake in the rule text. The caller's context never crosses, so
no context passed to a check could supply the key. Only the `with` map or the
target's own default can.

A `with` entry whose value is an absent `@context` key still supplies the key, as
`null`. The rule named it, and the value is the caller's to pass.

A key the target does not require that you forget is simply absent, and an absent
optional key is fail-closed: it can remove access, never restore it.

:::caution[`for` is what resets the context]
These two are not the same rule.

```warrant
if can(read)                                  # same schema, same row, context kept
if can(read for documents(@column id))        # a boundary: context starts afresh
```

Both ask about the same ability on the same row. The second crosses a boundary, and
a boundary is exactly what context does not cross. Write the `for` clause only when
you mean a different row or a different schema.
:::

## The no-boundary form

Leaving `for` off asks about another ability of *this* schema, over the row this
rule is already about:

```warrant
for documents {
    if is_owner they can read
    if can(read) they can comment
    if can(comment) they can share
}
```

Nothing is crossed, so it takes no `with` map and no `as`, the context comes along
unchanged, and it emits no subquery. If that context lacks a key the named ability
requires, the caller did not pass it, so the reference answers unknown rather than
throwing: it neither grants nor lifts a deny. The named ability's predicate compiles
straight into the frame the reference sits in, so the whole chain above collapses
to one `documents.owner_id = ?`.
