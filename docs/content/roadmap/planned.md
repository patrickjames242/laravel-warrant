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
its scope while clauses at an outer level can override it. The design lives in
`specs/scopes.md`.

Status: **design**, not implemented.

## A language server

The [editor tooling](/editors/overview/) today is syntax highlighting. A TextMate
grammar and a tree-sitter grammar are both lexers, so neither can know whether
`is_mne` is a real condition on this schema.

The planned answer is a language server reusing this repository's own PHP parser,
giving live syntax diagnostics, hover, and completion of condition and ability
names, for `.warrant` files and `WARRANT` heredocs alike. One server, serving a VS
Code extension, a native PhpStorm plugin, and Zed, which speaks LSP natively.

It also stops the language being written down twice, which is the current cost of
supporting both TextMate and tree-sitter.

The design lives in `specs/language-server.md`.

Status: **design**, with the highlighting step shipped.

## Until then

For the scopes gap, write exceptions into the denial with `and not`, and keep
absolute denials in [`rules()`](/schemas/schema-policy/) where nothing can
edit around them.

For the language-server gap, run
[`validate()`](/supplying-rules/validation/) in CI over every stored rule, which
catches the same class of mistake a few seconds later.
