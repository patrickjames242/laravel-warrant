---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: What is planned
description: Named work in progress, with enough detail to judge whether to wait for it.
sidebar:
  order: 1
---

Two things are designed and not yet built. Both have a spec in the repository under
`specs/`.

## Scopes: locally overridable denials

Today a `cannot` is an absolute veto. Once any denial matches, no grant anywhere
brings the ability back:

```text
predicate(ability) =
    ( OR of each `can` rule's if-expression )
    AND ( AND of NOT(each `cannot` rule's if-expression) )
```

That is simple and order-independent, and it has a ceiling. It is the same
limitation AWS IAM has with "explicit deny always wins".

In practice people need denials that are **local**, meaning "deny within this
context, but an outer rule may still re-grant", alongside one that is **absolute**.
The flat model can only express the absolute kind.

What that costs you today: an exception has to be written into the denial itself,
by whoever owns the denial.

```warrant
if is_locked and not is_admin they cannot update
```

So a rule set assembled from three sources cannot have the third carve an exception
out of the first's `cannot`. That is the constraint to design around when planning
a permission hierarchy. See
[composing from several sources](/supplying-rules/composing/).

Scopes add lexical structure to a rule set, so that a `cannot` applies only within
its scope while can/cannot clauses at an outer level can override it. The design lives in
`specs/scopes.md`.

Status: **design**, not implemented.

## The language server in Zed

The [language server](/editors/language-server/) runs in VS Code and PhpStorm. Zed
speaks LSP natively, but its extension does not start the server yet.

Status: **planned**.

## Until then

For the scopes gap, write exceptions into the denial with `and not`, and keep
absolute denials in [`rules()`](/schemas/schema-policy/) where nothing can
edit around them.

For rules the language server does not see, such as rows in a database, run
[`validate()`](/supplying-rules/validation/) in CI over every stored rule.
